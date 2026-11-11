<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create computers table.
     * Tracks physical lab machines and their live status.
     */
    public function up(): void
    {
        Schema::create('computers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lab_id')
                ->constrained('labs')
                ->restrictOnDelete();

            $table->string('computer_code')->unique(); // Example: LAB2-PC16

            $table->string('hostname')->nullable();
            $table->string('ip_address');
            $table->string('mac_address')->nullable();

            $table->string('os')->nullable();
            $table->string('browser')->nullable();
            $table->string('agent_version')->nullable();

            $table->enum('status', [
                'online',
                'offline',
                'exam',
                'maintenance',
                'locked',
            ])->default('offline');

            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index('lab_id');
            $table->index('status');
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computers');
    }
};