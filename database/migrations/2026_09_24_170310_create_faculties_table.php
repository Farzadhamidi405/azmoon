<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create faculties table.
     * Faculties group subjects, but some general subjects may not belong to a faculty.
     */
    public function up(): void
    {
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();

            // Example: Faculty of Computer Science.
            $table->string('name');

            // Short unique code, example: CS, LAW, ENG.
            $table->string('code')->unique();

            $table->timestamps();
        });
    }

    /**
     * Drop faculties table.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculties');
    }
};