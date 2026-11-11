<?php

namespace App\Policies;

use App\Models\User;

/**
 * ShiftPolicy - authorization rules for shifts.
 * Reuses the existing "labs.*" permissions (no new permissions/seeding).
 * All methods are class-level: they only check the user's permission.
 */
class ShiftPolicy
{
    /**
     * List records. Requires labs.view.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('labs.view');
    }

    /**
     * Show a single record. Requires labs.view.
     */
    public function view(User $user): bool
    {
        return $user->hasPermission('labs.view');
    }

    /**
     * Create a record. Requires labs.create.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('labs.create');
    }

    /**
     * Update a record. Requires labs.update.
     */
    public function update(User $user): bool
    {
        return $user->hasPermission('labs.update');
    }

    /**
     * Delete a record. Requires labs.delete.
     */
    public function delete(User $user): bool
    {
        return $user->hasPermission('labs.delete');
    }

    /**
     * Auto-assign computers (preview and final). Requires labs.update.
     */
    public function autoAssign(User $user): bool
    {
        return $user->hasPermission('labs.update');
    }
}