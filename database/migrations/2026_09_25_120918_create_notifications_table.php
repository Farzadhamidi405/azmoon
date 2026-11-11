<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create system notifications table.
     * Notifications can target one user or all relevant admins when user is null.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Example: candidate_disconnected, backup_failed, suspicious_session.
            $table->string('type');

            $table->string('title');
            $table->text('body')->nullable();

            // Polymorphic-like reference without strict FK.
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->boolean('is_read')->default(false);

            // Null means notification is visible to all relevant admins.
            $table->foreignId('notifiable_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('type');
            $table->index('is_read');
            $table->index('notifiable_user_id');
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};