<?php

namespace App\Policies;

use App\Models\User;

/**
 * Policy برای کنترل دسترسی به مدیریت کاربران Staff.
 * فقط Super Admin می‌تواند کاربران را مدیریت کند.
 * بند 3 SRS — نقش‌های سیستم.
 */
class UserPolicy
{
    /**
     * مشاهده لیست کاربران.
     */
    public function viewAny(User $user): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * مشاهده یک کاربر خاص.
     */
    public function view(User $user, User $model): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * ایجاد کاربر جدید.
     */
    public function create(User $user): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * ویرایش کاربر.
     * Super Admin نمی‌تواند role خودش را تغییر دهد.
     */
    public function update(User $user, User $model): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * غیرفعال‌کردن کاربر.
     * Super Admin نمی‌تواند خودش را غیرفعال کند.
     */
    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }
        return $user->role?->name === 'Super Admin';
    }
}