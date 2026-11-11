<?php

namespace App\Services;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * سرویس فقط‌خواندنی برای جستجوی Audit Log.
 * منطق فیلتر از Controller جدا شده تا Controller نازک بماند.
 */
class AuditLogQueryService
{
    private const DEFAULT_PER_PAGE = 20;

    /**
     * لیست صفحه‌بندی‌شده لاگ‌ها با فیلترهای اختیاری.
     *
     * @param array<string,mixed> $filters خروجی validated() درخواست
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return AuditLog::query()
            // Eager Loading فقط ستون‌های لازم کاربر → جلوگیری از N+1
            ->with('user:id,name,username')
            ->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['target_type'] ?? null, fn ($q, $v) => $q->where('target_type', $v))
            ->when($filters['target_id'] ?? null, fn ($q, $v) => $q->where('target_id', $v))
            ->when($filters['ip_address'] ?? null, fn ($q, $v) => $q->where('ip_address', $v))
            // از whereDate استفاده نشده تا Index روی created_at قابل استفاده بماند
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            // جدیدترین اول؛ id برای ترتیب پایدار هنگام created_at برابر
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}