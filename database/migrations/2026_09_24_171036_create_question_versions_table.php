<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable history of question changes.
     * Previous versions are never deleted from business logic.
     */
    public function up(): void
    {
        Schema::create('question_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('question_id')
                ->constrained('questions')
                ->cascadeOnDelete();

            $table->integer('version_number');

            $table->text('question_text');
            $table->string('question_type');
            $table->string('section_name');
            $table->string('difficulty');

            // Snapshot of all options and correct answers.
            $table->json('options_snapshot');

            $table->text('passage_snapshot')->nullable();

            $table->foreignId('media_id')
                ->nullable()
                ->constrained('media')
                ->nullOnDelete();

            $table->foreignId('changed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('change_reason')->nullable();

            $table->timestamps();

            $table->unique(['question_id', 'version_number']);
            $table->index('question_id');
        });

        // Attach current version pointer after question_versions table exists.
        Schema::table('questions', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('question_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('question_versions');
    }
};