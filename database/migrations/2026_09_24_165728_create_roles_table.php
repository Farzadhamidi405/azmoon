<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create system roles table.
     * Roles define high-level staff access groups.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            // Example: Super Admin, Exam Administrator, Question Manager.
            $table->string('name')->unique();

            // System roles cannot be deleted from admin panel.
            $table->boolean('is_system_role')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Drop roles table.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};