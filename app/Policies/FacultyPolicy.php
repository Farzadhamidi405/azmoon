<?php

namespace App\Policies;

use App\Models\Faculty;
use App\Models\User;

/**
 * Policy مربوط به مدیریت دانشکده‌ها.
 *
 * دسترسی بر اساس ماتریس Permission در دیتابیس (faculties.*) تعیین می‌شود،
 * نه بر اساس نام نقش — الگوی مشابه AuditLogPolicy.
 * بند 60.2 SRS — چندمضمونی/چنددانشکده‌ای.
 *
 * قانون حذف (وابستگی به Subject) در Controller بررسی می‌شود،
 * نه در Policy — چون Policy فقط اجازهٔ تلاش را تعیین می‌کند،
 * نه معتبر بودن آن حذف خاص را.
 */
class FacultyPolicy
{
    /** مشاهده لیست دانشکده‌ها */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('faculties.view');
    }

    /** مشاهده جزئیات یک دانشکده */
    public function view(User $user, Faculty $faculty): bool
    {
        return $user->hasPermission('faculties.view');
    }

    /** ایجاد دانشکده جدید */
    public function create(User $user): bool
    {
        return $user->hasPermission('faculties.create');
    }

    /** ویرایش دانشکده */
    public function update(User $user, Faculty $faculty): bool
    {
        return $user->hasPermission('faculties.update');
    }

    /** حذف دانشکده */
    public function delete(User $user, Faculty $faculty): bool
    {
        return $user->hasPermission('faculties.delete');
    }
}