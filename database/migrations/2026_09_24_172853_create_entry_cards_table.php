<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create entry cards table.
     * QR token is secure and one-time; raw password must never be stored here.
     */
    public function up(): void
    {
        Schema::create('entry_cards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('candidate_id')
                ->constrained('candidates')
                ->cascadeOnDelete();

            $table->string('qr_token')->unique();
            $table->timestamp('qr_expires_at');
            $table->timestamp('printed_at')->nullable();

            $table->timestamps();

            $table->index('candidate_id');
            $table->index('qr_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_cards');
    }
};





        
    


