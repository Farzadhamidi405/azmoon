<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

/**
 * QuestionPolicy — کنترل دسترسی به سوالات بر اساس نقش کاربر.
 *
 * Super Admin / Exam Administrator : دسترسی کامل
 * Question Manager : ایجاد + ویرایش سوال خودش (فقط draft/under_review)
 * Reviewer : مشاهده + تغییر وضعیت (فقط under_review → approved/draft)
 * سایر نقش‌ها : بدون دسترسی
 */
class QuestionPolicy
{
    /**
     * بررسی اینکه آیا کاربر دسترسی کامل دارد.
     * Super Admin و Exam Administrator همیشه مجاز هستند.
     */
    private function isFullAccess(User $user): bool
    {
        return in_array($user->role->name, ['Super Admin', 'Exam Administrator']);
    }

    /**
     * بررسی نقش Question Manager.
     */
    private function isQuestionManager(User $user): bool
    {
        return $user->role->name === 'Question Manager';
    }

    /**
     * بررسی نقش Reviewer.
     */
    private function isReviewer(User $user): bool
    {
        return $user->role->name === 'Reviewer';
    }

    /**
     * مشاهده لیست سوالات.
     * Super Admin، Exam Administrator، Question Manager، Reviewer مجاز هستند.
     */
    public function viewAny(User $user): bool
    {
        return $this->isFullAccess($user)
            || $this->isQuestionManager($user)
            || $this->isReviewer($user);
    }

    /**
     * مشاهده یک سوال خاص.
     * همان نقش‌های viewAny مجاز هستند.
     */
    public function view(User $user, Question $question): bool
    {
        return $this->isFullAccess($user)
            || $this->isQuestionManager($user)
            || $this->isReviewer($user);
    }

    /**
     * ایجاد سوال جدید.
     * Super Admin، Exam Administrator، Question Manager مجاز هستند.
     */
    public function create(User $user): bool
    {
        return $this->isFullAccess($user)
            || $this->isQuestionManager($user);
    }

    /**
     * ویرایش سوال.
     * Super Admin / Exam Administrator: همه سوالات.
     * Question Manager: فقط سوال خودش و فقط در وضعیت draft یا under_review.
     */
    public function update(User $user, Question $question): bool
    {
        if ($this->isFullAccess($user)) {
            return true;
        }

        if ($this->isQuestionManager($user)) {
            return $question->created_by === $user->id
                && in_array($question->status, ['draft', 'under_review']);
        }

        return false;
    }

    /**
     * تغییر وضعیت سوال — Approval Workflow.
     *
     * Super Admin / Exam Administrator : هر انتقالی مجاز است.
     * Question Manager : فقط draft → under_review (سوال خودش).
     * Reviewer : فقط under_review → approved یا under_review → draft (رد).
     */
    public function changeStatus(User $user, Question $question): bool
    {
        if ($this->isFullAccess($user)) {
            return true;
        }

        if ($this->isQuestionManager($user)) {
            return $question->created_by === $user->id
                && $question->status === 'draft';
        }

        if ($this->isReviewer($user)) {
            return $question->status === 'under_review';
        }

        return false;
    }

    /**
     * حذف سوال (Soft Delete).
     * فقط Super Admin و Exam Administrator مجاز هستند.
     */
    public function delete(User $user, Question $question): bool
    {
        return $this->isFullAccess($user);
    }

    /**
     * مشاهده تاریخچه نسخه‌های سوال.
     * همان کسانی که می‌توانند سوال را ببینند.
     */
    public function versions(User $user, Question $question): bool
    {
        return $this->view($user, $question);
    }
}