<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create final attempt result table.
     * Kept separate from attempts for clean reporting and manual recalculation.
     */
    public function up(): void
    {
        Schema::create('attempt_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')
                ->unique()
                ->constrained('attempts')
                ->cascadeOnDelete();

            $table->decimal('total_score', 6, 2);
            $table->decimal('percentage', 5, 2);

            $table->enum('pass_fail', ['pass', 'fail']);

            // Manual means a reviewer adjusted the result.
            $table->enum('calculated_by', ['system', 'manual'])->default('system');

            $table->foreignId('recalculated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('calculated_at')->useCurrent();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_results');
    }
};