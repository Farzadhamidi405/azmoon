<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

/**
 * Policy برای کنترل دسترسی به عملیات نسخه‌برداری (Backup).
 * تمام عملیات فقط برای Super Admin مجاز است — بدون ماژول permission
 * مجزا در PermissionSeeder (طبق تصمیم پروژه).
 */
class BackupPolicy
{
    /**
     * مشاهده لیست نسخه‌های پشتیبان.
     */
    public function viewAny(User $user): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * مشاهده جزئیات یک نسخهٔ پشتیبان.
     */
    public function view(User $user, Backup $backup): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * ایجاد نسخهٔ پشتیبان جدید.
     */
    public function create(User $user): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * دانلود فایل نسخهٔ پشتیبان.
     */
    public function download(User $user, Backup $backup): bool
    {
        return $user->role?->name === 'Super Admin';
    }

    /**
     * حذف نسخهٔ پشتیبان (فایل و رکورد).
     */
    public function delete(User $user, Backup $backup): bool
    {
        return $user->role?->name === 'Super Admin';
    }
}