<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create attempt answers table.
     * Auto-save writes here and must be idempotent.
     */
    public function up(): void
    {
        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')
                ->constrained('attempts')
                ->cascadeOnDelete();

            $table->foreignId('exam_snapshot_question_id')
                ->constrained('exam_snapshot_questions')
                ->restrictOnDelete();

            // For single/multi/true_false questions.
            $table->json('selected_option_ids')->nullable();

            // For short_answer.
            $table->text('answer_text')->nullable();

            $table->boolean('is_correct')->nullable();
            $table->decimal('score_awarded', 5, 2)->nullable();

            $table->integer('time_spent_seconds')->default(0);
            $table->integer('changed_count')->default(0);

            // Prevent duplicate processing of retried HTTP requests.
            $table->string('idempotency_key')->unique();

            // Client time is informational only; backend time is source of truth.
            $table->dateTime('client_timestamp')->nullable();
            $table->dateTime('server_timestamp')->useCurrent();
           

            $table->enum('sync_status', ['synced', 'pending', 'conflict'])
                ->default('synced');

            $table->dateTime('answered_at');

            $table->timestamps();

            // One answer row per question per attempt.
            $table->unique(['attempt_id', 'exam_snapshot_question_id']);

            $table->index('attempt_id');
            $table->index('exam_snapshot_question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_answers');
    }
};