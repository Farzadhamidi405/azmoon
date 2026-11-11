<?php

namespace App\Policies;

use App\Models\ManualOverride;
use App\Models\User;

/**
 * Policy برای کنترل دسترسی به Manual Override.
 * بند 27 SRS — فقط Super Admin و Exam Administrator
 * می‌توانند Override انجام دهند.
 * Override ها immutable هستند — update/delete مجاز نیست.
 */
class ManualOverridePolicy
{
    /**
     * مشاهده لیست Override های یک Attempt.
     * Super Admin و Exam Administrator مجازند.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
        ]);
    }

    /**
     * مشاهده یک Override خاص.
     */
    public function view(User $user, ManualOverride $manualOverride): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
        ]);
    }

    /**
     * ثبت Override جدید.
     * هر Override نیازمند دلیل است — بدون دلیل ثبت نمی‌شود.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
        ]);
    }
}