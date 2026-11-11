<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Policy مربوط به Audit Log.
 *
 * دسترسی بر اساس ماتریس Permission در دیتابیس (audit.view) تعیین می‌شود،
 * نه بر اساس نام نقش. لاگ‌ها طبق SRS بند ۳۳ غیرقابل ویرایش/حذف هستند؛
 * بنابراین هیچ ability برای create/update/delete مجاز نیست.
 */
class AuditLogPolicy
{
    /** مشاهده لیست لاگ‌ها */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('audit.view');
    }

    /** مشاهده جزئیات یک لاگ */
    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->hasPermission('audit.view');
    }

    /** لاگ‌ها immutable هستند — هیچ‌کس نمی‌تواند ایجاد دستی کند */
    public function create(User $user): bool
    {
        return false;
    }

    /** لاگ‌ها immutable هستند — ویرایش ممنوع */
    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    /** لاگ‌ها immutable هستند — حذف ممنوع */
    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}