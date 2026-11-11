<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create backups table.
     * Tracks manual/scheduled backups and secure temporary download links.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();

            $table->enum('backup_type', ['full', 'incremental'])->default('full');

            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->enum('status', [
                'pending',
                'running',
                'completed',
                'failed',
            ])->default('pending');

            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            // Null means scheduled/system backup.
            $table->foreignId('initiated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('triggered_by', ['manual', 'scheduled'])->default('scheduled');

            // Used to verify backup integrity before restore.
            $table->string('checksum')->nullable();

            // Example: local, s3.
            $table->string('storage_location')->nullable();

            $table->text('failure_reason')->nullable();

            // Auto cleanup policy.
            $table->dateTime('retention_until')->nullable();

            // Temporary signed download token.
            $table->string('download_token')->nullable();
            $table->dateTime('download_expires_at')->nullable();

            // Email sending status is separate from backup status.
            $table->dateTime('email_sent_at')->nullable();
            $table->string('email_error')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('triggered_by');
            $table->index('initiated_by');
            $table->index('download_token');
            $table->index('retention_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};