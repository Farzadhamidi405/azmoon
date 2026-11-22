<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ensures the exam_questions pivot table exists with all required foreign keys,
     * indexes and constraints for linking questions to exams.
     */
    public function up(): void
    {
        if (!Schema::hasTable('exam_questions')) {
            Schema::create('exam_questions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('exam_id')
                    ->constrained('exams')
                    ->cascadeOnDelete();

                $table->foreignId('question_id')
                    ->constrained('questions')
                    ->cascadeOnDelete();

                $table->foreignId('exam_section_id')
                    ->nullable()
                    ->constrained('exam_sections')
                    ->nullOnDelete();

                $table->integer('display_order')->default(1);
                $table->decimal('score', 5, 2)->nullable();

                $table->timestamps();

                $table->unique(['exam_id', 'question_id']);
                $table->index(['exam_id', 'display_order']);
            });
        } else {
            // If table already exists, ensure all required columns exist
            Schema::table('exam_questions', function (Blueprint $table) {
                if (!Schema::hasColumn('exam_questions', 'exam_section_id')) {
                    $table->foreignId('exam_section_id')
                        ->nullable()
                        ->after('question_id')
                        ->constrained('exam_sections')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn('exam_questions', 'display_order')) {
                    $table->integer('display_order')->default(1)->after('exam_section_id');
                }

                if (!Schema::hasColumn('exam_questions', 'score')) {
                    $table->decimal('score', 5, 2)->nullable()->after('display_order');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
