<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

/**
 * Policy برای کنترل دسترسی به عملیات Incident.
 * بند 26 SRS — فقط Lab Manager، Exam Administrator، و Super Admin
 * می‌توانند حوادث را مدیریت کنند.
 */
class IncidentPolicy
{
    /**
     * مشاهده لیست حوادث.
     * Super Admin، Exam Administrator، و Lab Manager مجازند.
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
     * مشاهده یک حادثه خاص.
     */
    public function view(User $user, Incident $incident): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Lab Manager',
        ]);
    }

    /**
     * ثبت حادثه جدید.
     * Lab Manager نیز می‌تواند حادثه ثبت کند — او در لابراتوار است.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Lab Manager',
        ]);
    }

    /**
     * ویرایش حادثه.
     * فقط حوادث open قابل ویرایش هستند.
     */
    public function update(User $user, Incident $incident): bool
    {
        // حادثه resolved دیگر قابل ویرایش نیست
        if ($incident->isResolved()) {
            return false;
        }

        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Lab Manager',
        ]);
    }

    /**
     * Resolve کردن حادثه — علامت‌گذاری به‌عنوان حل‌شده.
     */
    public function resolve(User $user, Incident $incident): bool
    {
        // حادثه resolved دیگر قابل Resolve نیست
        if ($incident->isResolved()) {
            return false;
        }

        return in_array($user->role?->name, [
            'Super Admin',
            'Exam Administrator',
            'Lab Manager',
        ]);
    }
}