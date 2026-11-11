<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create permissions table.
     * Each permission belongs to one module and one action.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            // Module name: exams, questions, candidates, labs, results, users, settings, ...
            $table->string('module');

            // Basic CRUD action.
            $table->enum('action', ['view', 'create', 'update', 'delete']);

            $table->timestamps();

            // Prevent duplicate permission definitions.
            $table->unique(['module', 'action']);
        });
    }

    /**
     * Drop permissions table.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};