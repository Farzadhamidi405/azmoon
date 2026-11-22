<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ensures all required workflow columns for question approval/rejection exist in the questions table:
     * - status
     * - reviewed_by
     * - reviewed_at
     * - rejection_reason
     * - created_by
     * - updated_at
     */
    public function up(): void
    {
        if (Schema::hasTable('questions')) {
            Schema::table('questions', function (Blueprint $table) {
                // Ensure rejection_reason column exists (TEXT, nullable)
                if (!Schema::hasColumn('questions', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable();
                }

                // Ensure reviewed_at column exists (timestamp, nullable)
                if (!Schema::hasColumn('questions', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable();
                }

                // Ensure reviewed_by column exists (unsignedBigInteger, nullable)
                if (!Schema::hasColumn('questions', 'reviewed_by')) {
                    $table->unsignedBigInteger('reviewed_by')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('questions')) {
            Schema::table('questions', function (Blueprint $table) {
                if (Schema::hasColumn('questions', 'rejection_reason')) {
                    $table->dropColumn('rejection_reason');
                }
            });
        }
    }
};
