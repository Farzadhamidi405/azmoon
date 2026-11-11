<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store lab/exam incidents.
     * Incidents can be linked to candidate, computer, and attempt when available.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('candidate_id')
                ->nullable()
                ->constrained('candidates')
                ->nullOnDelete();

            $table->foreignId('computer_id')
                ->nullable()
                ->constrained('computers')
                ->nullOnDelete();

            $table->foreignId('attempt_id')
                ->nullable()
                ->constrained('attempts')
                ->nullOnDelete();

            $table->text('problem_description');
            $table->text('action_taken')->nullable();

            $table->foreignId('responsible_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('status', ['open', 'resolved'])->default('open');

            $table->dateTime('occurred_at');
            $table->dateTime('resolved_at')->nullable();

            $table->timestamps();

            $table->index('candidate_id');
            $table->index('computer_id');
            $table->index('attempt_id');
            $table->index('responsible_user_id');
            $table->index('status');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};