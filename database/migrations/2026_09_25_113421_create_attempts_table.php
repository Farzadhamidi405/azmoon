<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create attempts table.
     * Attempt is the server-side source of truth for exam timing and status.
     */
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('candidate_id')
                ->constrained('candidates')
                ->restrictOnDelete();

            $table->foreignId('exam_id')
                ->constrained('exams')
                ->restrictOnDelete();

            $table->foreignId('candidate_assignment_id')
                ->constrained('candidate_assignments')
                ->restrictOnDelete();

            $table->foreignId('exam_snapshot_id')
                ->constrained('exam_snapshots')
                ->restrictOnDelete();

            $table->foreignId('computer_id')
                ->constrained('computers')
                ->restrictOnDelete();

            $table->string('ip_address');
            $table->string('browser')->nullable();
            $table->string('device')->nullable();

            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('last_activity_at');

            $table->dateTime('submitted_at')->nullable();

            $table->enum('status', [
                'in_progress',
                'disconnected',
                'completed',
                'auto_submitted',
                'void',
            ])->default('in_progress');

            // Used to detect multiple login and validate active exam session.
            $table->string('session_token')->unique()->nullable();

            $table->enum('connection_status', ['connected', 'disconnected'])
                ->default('connected');

            $table->dateTime('last_heartbeat_at')->nullable();
            $table->dateTime('disconnected_at')->nullable();
            $table->integer('reconnect_count')->default(0);

            $table->integer('answered_count')->default(0);
            $table->integer('correct_count')->nullable();
            $table->integer('wrong_count')->nullable();

            $table->decimal('score', 6, 2)->nullable();

            $table->timestamps();

            $table->index('candidate_id');
            $table->index('exam_id');
            $table->index('candidate_assignment_id');
            $table->index('status');
            $table->index('session_token');
            $table->index('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};