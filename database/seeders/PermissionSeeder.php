<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * All modules and their allowed actions.
     * Super Admin gets all permissions automatically via hasPermission() check.
     * Other roles get only what is listed in $rolePermissions below.
     */
    public function run(): void
    {
        // Define all module + action combinations.
        $permissions = [
            // Exam management
            ['module' => 'exams',         'action' => 'view'],
            ['module' => 'exams',         'action' => 'create'],
            ['module' => 'exams',         'action' => 'update'],
            ['module' => 'exams',         'action' => 'delete'],

            // Question bank
            ['module' => 'questions',     'action' => 'view'],
            ['module' => 'questions',     'action' => 'create'],
            ['module' => 'questions',     'action' => 'update'],
            ['module' => 'questions',     'action' => 'delete'],

            // Candidate management
            ['module' => 'candidates',    'action' => 'view'],
            ['module' => 'candidates',    'action' => 'create'],
            ['module' => 'candidates',    'action' => 'update'],
            ['module' => 'candidates',    'action' => 'delete'],

            // Lab and computer management
            ['module' => 'labs',          'action' => 'view'],
            ['module' => 'labs',          'action' => 'create'],
            ['module' => 'labs',          'action' => 'update'],
            ['module' => 'labs',          'action' => 'delete'],

            // Exam sessions and attempts
            ['module' => 'sessions',      'action' => 'view'],
            ['module' => 'sessions',      'action' => 'update'],

            // Results
            ['module' => 'results',       'action' => 'view'],
            ['module' => 'results',       'action' => 'update'],

            // User management
            ['module' => 'users',         'action' => 'view'],
            ['module' => 'users',         'action' => 'create'],
            ['module' => 'users',         'action' => 'update'],
            ['module' => 'users',         'action' => 'delete'],

            // Roles and permissions
            ['module' => 'roles',         'action' => 'view'],
            ['module' => 'roles',         'action' => 'create'],
            ['module' => 'roles',         'action' => 'update'],
            ['module' => 'roles',         'action' => 'delete'],

            // Audit logs
            ['module' => 'audit',         'action' => 'view'],

            // Anti-cheat
            ['module' => 'anticheat',     'action' => 'view'],

            // Incidents
            ['module' => 'incidents',     'action' => 'view'],
            ['module' => 'incidents',     'action' => 'create'],
            ['module' => 'incidents',     'action' => 'update'],

            // Manual override
            ['module' => 'overrides',     'action' => 'view'],
            ['module' => 'overrides',     'action' => 'create'],

            // Settings
            ['module' => 'settings',      'action' => 'view'],
            ['module' => 'settings',      'action' => 'update'],

            // Notifications
            ['module' => 'notifications', 'action' => 'view'],

           


            // Subjects and faculties
            ['module' => 'subjects',      'action' => 'view'],
            ['module' => 'subjects',      'action' => 'create'],
            ['module' => 'subjects',      'action' => 'update'],
            ['module' => 'subjects',      'action' => 'delete'],
            ['module' => 'subjects',      'action' => 'assign_access'],

            // Faculties
            ['module' => 'faculties',     'action' => 'view'],
            ['module' => 'faculties',     'action' => 'create'],
            ['module' => 'faculties',     'action' => 'update'],
            ['module' => 'faculties',     'action' => 'delete'],



        ];

        // Insert all permissions — skip duplicates.
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['module' => $perm['module'], 'action' => $perm['action']]
            );
        }

        // Assign permissions to each role.
        $this->assignRolePermissions();
    }

    /**
     * Assign specific permissions to each role.
     * Super Admin is excluded — hasPermission() grants full access directly.
     */
    private function assignRolePermissions(): void
    {
        $map = [

                        // Exam Administrator: full access except user/role management.
            'Exam Administrator' => [
                'exams.view',    'exams.create',    'exams.update',    'exams.delete',
                'questions.view','questions.create', 'questions.update','questions.delete',                'candidates.view','candidates.create','candidates.update','candidates.delete',
                'labs.view',     'labs.create',     'labs.update',     'labs.delete',
                'sessions.view', 'sessions.update',
                'results.view',  'results.update',
                'audit.view',
                'anticheat.view',
                'incidents.view','incidents.create','incidents.update',
                'overrides.view','overrides.create',
                'notifications.view',
                'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete', 'subjects.assign_access',
                'faculties.view', 'faculties.create', 'faculties.update', 'faculties.delete',
                'settings.view',
            ],

            // Question Manager: question bank only (subject-level filtered in policy).
            'Question Manager' => [
                'questions.view', 'questions.create', 'questions.update',
                'subjects.view',
                'exams.view',
            ],

            // Reviewer: can approve/reject questions.
            'Reviewer' => [
                'questions.view', 'questions.update',
                'subjects.view',
                'exams.view',
            ],

            // Invigilator: monitor sessions and check in candidates.
            'Invigilator' => [
                'sessions.view',  'sessions.update',
                'candidates.view','candidates.update',
                'labs.view',
                'incidents.view', 'incidents.create','incidents.update',
                'anticheat.view',
                'notifications.view',
            ],

            // Result Reviewer: read-only results and reports.
            'Result Reviewer' => [
                'results.view',
                'exams.view',
                'candidates.view',
                'anticheat.view',
                'audit.view',
                'notifications.view',
            ],

            // Lab Manager: labs, computers, sessions, check-in.
            'Lab Manager' => [
                'labs.view',     'labs.create',    'labs.update',
                'sessions.view', 'sessions.update',
                'candidates.view','candidates.update',
                'incidents.view','incidents.create','incidents.update',
                'overrides.view','overrides.create',
                'anticheat.view',
                'notifications.view',
                'results.view',
            ],
        ];

        foreach ($map as $roleName => $permissionKeys) {
            $role = Role::where('name', $roleName)->first();

            if (!$role) {
                continue;
            }

            $permissionIds = [];

            foreach ($permissionKeys as $key) {
                [$module, $action] = explode('.', $key);

                $perm = Permission::where('module', $module)
                    ->where('action', $action)
                    ->first();

                if ($perm) {
                    $permissionIds[] = $perm->id;
                }
            }

            // sync without detaching to avoid duplicate key errors on re-run.
            $role->permissions()->sync($permissionIds);
        }
    }
}