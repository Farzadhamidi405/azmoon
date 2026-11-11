<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed system roles.
     * System roles cannot be deleted from the admin panel.
     * Report Viewer is intentionally excluded — its permissions
     * are covered by Result Reviewer role.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'Super Admin',           'is_system_role' => true],
            ['name' => 'Exam Administrator',    'is_system_role' => true],
            ['name' => 'Question Manager',      'is_system_role' => true],
            ['name' => 'Reviewer',              'is_system_role' => true],
            ['name' => 'Invigilator',           'is_system_role' => true],
            ['name' => 'Result Reviewer',       'is_system_role' => true],
            ['name' => 'Lab Manager',           'is_system_role' => true],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                ['is_system_role' => $role['is_system_role']]
            );
        }
    }
}