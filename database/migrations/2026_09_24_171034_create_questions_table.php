<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Main question bank table.
     * Questions are subject-based and support variable options.
     */
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete();

            // Free text section name: Grammar, Reading, Algebra, etc.
            $table->string('section_name');

            $table->enum('question_type', [
                'single',
                'multi',
                'true_false',
                'short_answer',
            ])->default('single');

            $table->text('question_text');

            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');

            $table->string('topic')->nullable();

            $table->decimal('score', 5, 2)->default(1.00);

            // Reading only.
            $table->foreignId('passage_id')
                ->nullable()
                ->constrained('passages')
                ->nullOnDelete();

            // Listening/video only.
            $table->foreignId('media_id')
                ->nullable()
                ->constrained('media')
                ->nullOnDelete();

            $table->enum('status', [
                'draft',
                'under_review',
                'approved',
                'active',
                'inactive',
                'archived',
            ])->default('draft');

            // Possible duplicate warning reference.
            $table->unsignedBigInteger('possible_duplicate_of')->nullable();

            // Filled after question_versions exists.
            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->unsignedBigInteger('reviewed_by')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('subject_id');
            $table->index('status');
            $table->index('section_name');
            $table->index('difficulty');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->foreign('possible_duplicate_of')
                ->references('id')
                ->on('questions')
                ->nullOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['possible_duplicate_of']);
            $table->dropForeign(['reviewed_by']);
        });

        Schema::dropIfExists('questions');
    }
};