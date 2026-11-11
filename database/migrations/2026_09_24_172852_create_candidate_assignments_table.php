<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create candidate assignments table.
     * Connects candidate to exam, shift, lab and optional computer.
     */
    public function up(): void
    {
        Schema::create('candidate_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('candidate_id')
                ->constrained('candidates')
                ->restrictOnDelete();

            $table->foreignId('exam_id')
                ->constrained('exams')
                ->restrictOnDelete();

            $table->foreignId('shift_id')
                ->constrained('shifts')
                ->restrictOnDelete();

            $table->foreignId('lab_id')
                ->constrained('labs')
                ->restrictOnDelete();

            // Nullable until check-in or auto-assign.
            $table->foreignId('computer_id')
                ->nullable()
                ->constrained('computers')
                ->nullOnDelete();

            // Null means system auto-assigned it.
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('assigned_at')->useCurrent();

            $table->timestamps();

            // A candidate can only be assigned once per exam.
            $table->unique(['candidate_id', 'exam_id']);

            $table->index('exam_id');
            $table->index('shift_id');
            $table->index('lab_id');
            $table->index('computer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_assignments');
    }
};