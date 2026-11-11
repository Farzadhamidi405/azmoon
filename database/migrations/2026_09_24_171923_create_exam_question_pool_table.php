<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Define random question pool rules for each exam section.
     * Actual selected questions are frozen later in exam_snapshot_questions.
     */
    public function up(): void
    {
        Schema::create('exam_question_pool', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_section_id')
                ->constrained('exam_sections')
                ->cascadeOnDelete();

            // Total available questions in the pool at publish time.
            $table->integer('pool_size');

            // Number of questions picked for each candidate/attempt.
            $table->integer('pick_count');

            $table->boolean('randomize_options')->default(true);

            $table->timestamps();

            $table->unique('exam_section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_question_pool');
    }
};