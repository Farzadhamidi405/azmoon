<?php

namespace App\Policies;

use App\Models\SessionTransfer;
use App\Models\User;

/**
 * Policy برای کنترل دسترسی به Session Transfer.
 * بند 22 SRS — Super Admin، Exam Administrator و Lab Manager
 * می‌توانند Session Transfer انجام دهند.
 */
class SessionTransferPolicy
{
    /**
     * مشاهده لیست Session Transfer های یک Attempt.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Lab Manager',
        ]);
    }

    /**
     * مشاهده یک Session Transfer خاص.
     */
    public function view(User $user, SessionTransfer $sessionTransfer): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Lab Manager',
        ]);
    }

    /**
     * ثبت Session Transfer جدید.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Lab Manager',
        ]);
    }
}