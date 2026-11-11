<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * Policy برای Notification.
 * فقط بررسی permission در این لایه انجام می‌شود؛ بررسی مالکیت (own/broadcast)
 * عمداً در Controller انجام می‌شود تا بتوان بین 403 (بدون permission)
 * و 404 (اعلان متعلق به کاربر دیگر) تمایز گذاشت.
 * Step 12.9.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('notifications.view');
    }

    public function view(User $user, Notification $notification): bool
    {
        return $user->hasPermission('notifications.view');
    }

    /**
     * اجازهٔ تلاش برای علامت‌گذاری خوانده‌شدن (بررسی مالکیت و broadcast در Controller است).
     */
    public function markAsRead(User $user, Notification $notification): bool
    {
        return $user->hasPermission('notifications.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Notification $notification): bool
    {
        return false;
    }

    public function delete(User $user, Notification $notification): bool
    {
        return false;
    }
}