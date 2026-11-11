<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create pivot table between roles and permissions.
     * A role can have many permissions.
     */
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();

            $table->timestamps();

            // Same permission cannot be attached twice to the same role.
            $table->unique(['role_id', 'permission_id']);
        });
    }

    /**
     * Drop role_permissions pivot table.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};