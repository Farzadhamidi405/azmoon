<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exam snapshot root table.
     * A snapshot is generated when an exam is published and must not be edited.
     */
    public function up(): void
    {
        Schema::create('exam_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_id')
                ->constrained('exams')
                ->restrictOnDelete();

            $table->timestamp('generated_at')->useCurrent();

            // Business rule: snapshot content is immutable after generation.
            $table->boolean('is_immutable')->default(true);

            $table->timestamps();

            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_snapshots');
    }
};