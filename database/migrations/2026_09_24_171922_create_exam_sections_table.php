<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create exam sections.
     * Section names are free text, so the system supports non-language subjects too.
     */
    public function up(): void
    {
        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_id')
                ->constrained('exams')
                ->cascadeOnDelete();

            // Examples: Grammar, Reading, Algebra, Geometry.
            $table->string('name');

            $table->integer('question_count');
            $table->decimal('total_marks', 6, 2);
            $table->integer('display_order')->default(0);

            $table->timestamps();

            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sections');
    }
};