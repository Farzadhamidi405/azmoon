<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create immutable audit logs table.
     * Audit logs record critical actions with old/new values and actor metadata.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable because some actions are performed by system jobs.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Snapshot of the user's role at action time.
            $table->string('role')->nullable();

            // Example: Created, Updated, Deleted, Published, LoginFailed.
            $table->string('action');

            // Example: exams, questions, candidates, attempts.
            $table->string('module');

            // Example: Exam, Question, Candidate.
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            // JSON snapshots for audit comparison.
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();

            $table->string('ip_address')->nullable();
            $table->string('device')->nullable();
            $table->text('user_agent')->nullable();

            // Audit table only needs created_at.
            $table->dateTime('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('module');
            $table->index('action');
            $table->index('target_type');
            $table->index('target_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};