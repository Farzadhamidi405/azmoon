<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Update Laravel default users table for the exam system.
     * We keep email_verified_at and remember_token for Laravel compatibility.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Email is still unique, but nullable because some staff accounts may login by username only.
            $table->string('email')->nullable()->change();

            // Staff username used for admin login.
            $table->string('username')->unique()->after('email');

            // Staff account activation flag.
            $table->boolean('is_active')->default(true)->after('remember_token');

            // Two factor authentication fields for privileged roles.
            $table->boolean('two_factor_enabled')->default(false)->after('is_active');
            $table->string('two_factor_secret')->nullable()->after('two_factor_enabled');

            // Login security fields.
            $table->integer('failed_login_attempts')->default(0)->after('two_factor_secret');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
        });
    }

    /**
     * Rollback only fields added/changed by this migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn([
                'username',
                'is_active',
                'two_factor_enabled',
                'two_factor_secret',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
            ]);

            // Restore Laravel default email behavior.
            $table->string('email')->nullable(false)->change();
        });
    }
};