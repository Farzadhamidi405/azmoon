<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create labs table.
     * Labs contain multiple computers for exam delivery.
     */
    public function up(): void
    {
        Schema::create('labs', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('location')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labs');
    }
};