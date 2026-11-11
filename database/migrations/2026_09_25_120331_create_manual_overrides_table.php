<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store manual administrative overrides.
     * Every override requires a reason and old/new values for auditability.
     */
    public function up(): void
    {
        Schema::create('manual_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')
                ->constrained('attempts')
                ->restrictOnDelete();

            $table->enum('action', [
                'extend_time',
                'reset_session',
                'transfer_session',
                'force_submit',
                'reopen_attempt',
                'change_computer',
                'mark_absent',
                'void_attempt',
            ]);

            $table->text('reason');

            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();

            $table->foreignId('performed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->dateTime('performed_at')->useCurrent();

            $table->timestamps();

            $table->index('attempt_id');
            $table->index('action');
            $table->index('performed_by');
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_overrides');
    }
};