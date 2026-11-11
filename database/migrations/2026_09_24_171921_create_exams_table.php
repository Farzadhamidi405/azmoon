<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create exams table.
     * Each exam belongs to one subject and follows the full exam lifecycle.
     */
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->integer('duration_minutes');
            $table->decimal('total_marks', 6, 2);
            $table->decimal('pass_marks', 6, 2);

            // Full lifecycle: Draft → Published → Running → Completed → Archived.
            $table->enum('status', [
                'draft',
                'under_review',
                'approved',
                'scheduled',
                'published',
                'running',
                'completed',
                'reviewed',
                'archived',
            ])->default('draft');

            $table->boolean('randomize_questions')->default(true);
            $table->boolean('randomize_options')->default(true);
            $table->boolean('allow_back_navigation')->default(true);
            $table->boolean('show_result_immediately')->default(false);

            // Display calendar override for this exam.
            $table->enum('default_calendar', ['shamsi', 'qamari', 'miladi'])->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index('subject_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};