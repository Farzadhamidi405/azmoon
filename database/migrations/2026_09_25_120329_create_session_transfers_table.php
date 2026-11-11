<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store exam session transfers from one computer to another.
     * This preserves attempt state while recording who transferred it and why.
     */
    public function up(): void
    {
        Schema::create('session_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')
                ->constrained('attempts')
                ->restrictOnDelete();

            $table->foreignId('from_computer_id')
                ->constrained('computers')
                ->restrictOnDelete();

            $table->foreignId('to_computer_id')
                ->constrained('computers')
                ->restrictOnDelete();

            $table->string('reason');

            $table->foreignId('transferred_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->dateTime('transferred_at')->useCurrent();

            $table->timestamps();

            $table->index('attempt_id');
            $table->index('from_computer_id');
            $table->index('to_computer_id');
            $table->index('transferred_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_transfers');
    }
};