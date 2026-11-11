<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

/**
 * Policy برای کنترل دسترسی به عملیات Exam.
 * کاربران فقط می‌توانند آزمون‌های مضامینی را ویرایش کنند که به آنها دسترسی دارند.
 */
class ExamPolicy
{
    /**
     * مشاهده لیست آزمون‌ها — همه Staff می‌توانند ببینند.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Question Manager',
            
        ]);










    }

    /**
     * مشاهده یک آزمون خاص.
     */
    public function view(User $user, Exam $exam): bool
    {
        // Super Admin = دسترسی کامل
        if ($user->role?->name === 'Super Admin') {
            return true;
        }

        // Exam Administrator = دسترسی کامل
        if ($user->role?->name === 'Exam Administrator') {
            return true;
        }

        // دیگران فقط اگر به مضمون دسترسی داشته باشند
        return $user->hasSubjectAccess($exam->subject_id);
    }

    /**
     * ایجاد آزمون جدید.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
        ]);
    }

    /**
     * ویرایش آزمون — فقط در وضعیت‌های قابل ویرایش.
     */
    public function update(User $user, Exam $exam): bool
    {
        // فقط Draft و Under Review قابل ویرایش است
        if (!in_array($exam->status, ['draft', 'under_review'])) {
            return false;
        }

        // Super Admin = دسترسی کامل
        if ($user->role?->name === 'Super Admin') {
            return true;
        }

        // Exam Administrator = دسترسی کامل
        if ($user->role?->name === 'Exam Administrator') {
            return true;
        }

        // دیگران فقط اگر creator باشند و به مضمون دسترسی داشته باشند
        return $user->id === $exam->created_by && 
               $user->hasSubjectAccess($exam->subject_id);
    }

    /**
     * حذف آزمون — فقط Draft قابل حذف است.
     */
    public function delete(User $user, Exam $exam): bool
    {
        if ($exam->status !== 'draft') {
            return false;
        }

        if ($user->role?->name === 'Super Admin') {
            return true;
        }

        if ($user->role?->name === 'Exam Administrator') {
            return true;
        }

        return $user->id === $exam->created_by && 
               $user->hasSubjectAccess($exam->subject_id);
    }

    /**
     * تغییر وضعیت آزمون.
     */
    public function changeStatus(User $user, Exam $exam): bool
    {
        // فقط Super Admin و Exam Administrator
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
        ]);


        

        
    }
}
