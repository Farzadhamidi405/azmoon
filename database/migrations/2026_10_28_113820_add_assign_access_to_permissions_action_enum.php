<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add 'assign_access' to the permissions.action enum.
     * Needed for Step 12.8 — granting subject access to Question Manager/Reviewer
     * is a distinct, sensitive action separate from subjects.update.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE permissions MODIFY action ENUM('view', 'create', 'update', 'delete', 'assign_access') NOT NULL"
        );
    }

    /**
     * Revert enum back to original 4 values.
     * Note: rollback will fail if any row currently has action = 'assign_access'.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE permissions MODIFY action ENUM('view', 'create', 'update', 'delete') NOT NULL"
        );
    }
};