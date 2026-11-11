<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create shifts table.
     * A shift belongs to one exam and one lab.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_id')
                ->constrained('exams')
                ->cascadeOnDelete();

            $table->foreignId('lab_id')
                ->constrained('labs')
                ->restrictOnDelete();

            $table->string('name'); // Example: Shift 1
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->integer('capacity');

            $table->timestamps();

            $table->index('exam_id');
            $table->index('lab_id');
            $table->index(['start_time', 'end_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};