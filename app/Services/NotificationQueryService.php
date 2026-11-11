<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * سرویس کوئری برای Notification — فیلتر، صفحه‌بندی و مالکیت.
 * Step 12.9.
 */
class NotificationQueryService
{
    private const DEFAULT_PER_PAGE = 20;

    /**
     * لیست صفحه‌بندی‌شدهٔ اعلان‌های قابل‌مشاهدهٔ کاربر (own + broadcast).
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        $query = $this->visibleTo($user);

        if (array_key_exists('is_read', $filters) && $filters['is_read'] !== null) {
            $query->where('is_read', filter_var($filters['is_read'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * تعداد اعلان‌های خوانده‌نشدهٔ قابل‌مشاهدهٔ کاربر.
     */
    public function unreadCount(User $user): int
    {
        return $this->visibleTo($user)->where('is_read', false)->count();
    }

    /**
     * علامت‌گذاری همهٔ اعلان‌های اختصاصی خوانده‌نشدهٔ کاربر.
     * اعلان‌های broadcast دست‌نخورده می‌مانند (راه‌حل الف — بند ۵).
     */
    public function markAllOwnAsRead(User $user): int
    {
        return Notification::query()
            ->where('notifiable_user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    /**
     * کوئری پایه برای اعلان‌های قابل‌مشاهدهٔ کاربر (own + broadcast).
     */
    private function visibleTo(User $user): Builder
    {
        return Notification::query()
            ->where(function (Builder $q) use ($user) {
                $q->where('notifiable_user_id', $user->id)
                    ->orWhereNull('notifiable_user_id');
            });
    }
}