<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create anti-cheat events table.
     * Events are evidence signals only; a single event is not proof of cheating.
     */
    public function up(): void
    {
        Schema::create('anti_cheat_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attempt_id')
                ->constrained('attempts')
                ->cascadeOnDelete();

            $table->enum('event_type', [
                'fullscreen_exit',
                'tab_switch',
                'window_blur',
                'copy',
                'paste',
                'right_click',
                'keyboard_shortcut',
                'print_attempt',
                'network_disconnect',
                'network_reconnect',
                'multiple_login',
                'ip_change',
                'browser_change',
                'computer_change',
                'session_transfer',
                'idle_time',
            ]);

            $table->json('meta')->nullable();

            $table->dateTime('occurred_at');

            $table->timestamps();

            $table->index('attempt_id');
            $table->index('event_type');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anti_cheat_events');
    }
};