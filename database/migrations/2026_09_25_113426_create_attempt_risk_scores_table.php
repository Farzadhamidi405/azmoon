<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create attempt risk score table.
     * Risk score is recalculated when anti-cheat events are added.
     */
    public function up(): void
    {
        Schema::create('attempt_risk_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')
                ->constrained('attempts')
                ->cascadeOnDelete();

            // 0 to 100.
            $table->integer('score')->default(0);

            $table->enum('level', ['low', 'medium', 'high'])->default('low');

           $table->dateTime('computed_at')->useCurrent();

            $table->timestamps();

            $table->index('attempt_id');
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_risk_scores');
    }
};