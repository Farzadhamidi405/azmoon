<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create subjects table.
     * The system is multi-subject, not hardcoded only for English.
     */
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();

            // Nullable because some subjects can be university-wide/general.
            $table->foreignId('faculty_id')
                ->nullable()
                ->constrained('faculties')
                ->nullOnDelete();

            // Example: English Language, Mathematics, Computer Science.
            $table->string('name');

            // Example: EN, MATH, CS.
            $table->string('code')->unique();

            $table->boolean('is_active')->default(true);

            // User who created this subject.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Fast filtering in admin lists.
            $table->index('faculty_id');
            $table->index('is_active');
        });
    }

    /**
     * Drop subjects table.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};