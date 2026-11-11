<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

/**
 * Policy مربوط به مدیریت مضامین (Subjects).
 *
 * دسترسی سطح ماژول بر اساس ماتریس Permission در دیتابیس (subjects.*) تعیین
 * می‌شود — الگوی مشابه AuditLogPolicy.
 *
 * علاوه بر Permission سطح ماژول، مشاهدهٔ یک مضمون خاص (view) همچنین باید
 * از طریق User::hasSubjectAccess() بررسی شود — بند 60.6 SRS: دسترسی
 * Question Manager/Reviewer باید محدود به مضامین تخصیص‌یافته باشد.
 * Super Admin و Exam Administrator به‌طور خودکار از این محدودیت معاف‌اند
 * چون hasSubjectAccess() برای آن‌ها همیشه true برمی‌گرداند.
 *
 * فیلتر لیست (index) بر اساس Subject Access در سطح Controller با Query
 * انجام می‌شود، نه در viewAny — چون viewAny نمی‌تواند روی یک مجموعه
 * فیلتر اعمال کند، فقط اجازهٔ ورود به Endpoint را می‌دهد.
 *
 * قانون حذف (وابستگی به Exam/Question/Passage) در Controller بررسی
 * می‌شود، نه در Policy.
 */
class SubjectPolicy
{
    /** مشاهده لیست مضامین — فیلتر دسترسی در Controller انجام می‌شود */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('subjects.view');
    }

    /** مشاهده جزئیات یک مضمون خاص */
    public function view(User $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.view')
            && $user->hasSubjectAccess($subject->id);
    }

    /** ایجاد مضمون جدید */
    public function create(User $user): bool
    {
        return $user->hasPermission('subjects.create');
    }

    /** ویرایش مضمون */
    public function update(User $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.update');
    }

    /** حذف مضمون */
    public function delete(User $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.delete');
    }

    /** تخصیص/لغو دسترسی مضمون به کاربران — بند 60.6 SRS */
    public function assignAccess(User $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.assign_access');
    }
}