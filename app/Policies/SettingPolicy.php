<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function view(User $user, Setting $setting): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('settings.update');
    }

    public function update(User $user, Setting $setting): bool
    {
        return $user->hasPermission('settings.update');
    }

    public function delete(User $user, Setting $setting): bool
    {
        return false;
    }
}
