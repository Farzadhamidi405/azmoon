<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create subject-level access table.
     * Question Managers and Reviewers can access only assigned subjects.
     */
    public function up(): void
    {
        Schema::create('user_subject_access', function (Blueprint $table) {
            $table->id();

            // Staff user who receives subject access.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Subject assigned to that user.
            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();

            // Admin user who granted this access.
            $table->foreignId('granted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('granted_at')->useCurrent();

            $table->timestamps();

            // One user can have only one access record per subject.
            $table->unique(['user_id', 'subject_id']);

            // Fast policy checks.
            $table->index('subject_id');
        });
    }

    /**
     * Drop subject access table.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subject_access');
    }
};