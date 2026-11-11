<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create section-level result table.
     * Used for detailed reports by exam section.
     */
    public function up(): void
    {
        Schema::create('attempt_section_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')
                ->constrained('attempts')
                ->cascadeOnDelete();

            $table->foreignId('exam_section_id')
                ->constrained('exam_sections')
                ->restrictOnDelete();

            $table->integer('correct_count')->default(0);
            $table->integer('wrong_count')->default(0);
            $table->integer('unanswered_count')->default(0);
            $table->decimal('score', 6, 2)->default(0);

            $table->timestamps();

            $table->unique(['attempt_id', 'exam_section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_section_results');
    }
};