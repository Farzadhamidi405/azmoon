<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add rejection_reason to questions table
        if (!Schema::hasColumn('questions', 'rejection_reason')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('status');
            });
        }

        // Update status enum for MySQL if needed
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE questions MODIFY status ENUM('draft', 'under_review', 'approved', 'active', 'inactive', 'archived', 'rejected') DEFAULT 'draft'");
        }

        // 2. Create exam_questions pivot table if not exists
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
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_questions');

        if (Schema::hasColumn('questions', 'rejection_reason')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropColumn('rejection_reason');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE questions MODIFY status ENUM('draft', 'under_review', 'approved', 'active', 'inactive', 'archived') DEFAULT 'draft'");
        }
    }
};
