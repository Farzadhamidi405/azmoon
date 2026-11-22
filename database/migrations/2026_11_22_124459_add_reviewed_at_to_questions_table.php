<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('questions', 'reviewed_at')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('questions', 'reviewed_at')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropColumn('reviewed_at');
            });
        }
    }
};
