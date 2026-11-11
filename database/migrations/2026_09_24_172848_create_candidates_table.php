<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create candidates table.
     * Candidates are separate from staff users and will use a separate auth guard later.
     */
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();

            $table->string('candidate_code')->unique(); // Example: KND001
            $table->string('name');
            $table->string('national_id')->nullable();

            $table->string('username')->unique();
            $table->string('password');

            $table->enum('status', [
                'present',
                'absent',
                'late',
                'in_progress',
                'completed',
                'disqualified',
                'cancelled',
            ])->default('present');

            $table->timestamp('checked_in_at')->nullable();

            // Candidate login security.
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();

            // Temporary credential controls for entry card/login.
            $table->boolean('must_reset_password')->default(false);
            $table->string('password_reset_token')->nullable();
            $table->timestamp('credential_expires_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('candidate_code');
            $table->index('status');
            $table->index('national_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};