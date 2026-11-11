<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frozen copy of every question used in a published exam.
     * Results must always be calculated from this snapshot, not from live question bank.
     */
    public function up(): void
    {
        Schema::create('exam_snapshot_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_snapshot_id')
                ->constrained('exam_snapshots')
                ->restrictOnDelete();

            // Traceability only. Content must be read from snapshot columns below.
            $table->foreignId('question_id')
                ->constrained('questions')
                ->restrictOnDelete();

            // Traceability to the exact question version.
            $table->foreignId('question_version_id')
                ->nullable()
                ->constrained('question_versions')
                ->nullOnDelete();

            $table->foreignId('exam_section_id')
                ->constrained('exam_sections')
                ->restrictOnDelete();

            $table->integer('display_order');
            $table->decimal('score', 5, 2);

            // Frozen question content.
            $table->text('question_text_snapshot');
            $table->string('question_type_snapshot');

            // Frozen options with correct answer info.
            $table->json('options_snapshot');

            // Frozen reading passage text if this is a Reading question.
            $table->text('passage_snapshot')->nullable();

            // Frozen media path and playback policy if this is Listening/Video.
            $table->json('media_snapshot')->nullable();

            // Used to reproduce randomized order if review is needed.
            $table->string('randomization_seed')->nullable();

            $table->timestamps();

            $table->index('exam_snapshot_id');
            $table->index('exam_section_id');
            $table->index('question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_snapshot_questions');
    }
};