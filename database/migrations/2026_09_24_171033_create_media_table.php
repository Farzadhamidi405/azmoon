<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audio/video files used by Listening and Video Listening questions.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->enum('type', ['audio', 'video']);
            $table->string('file_path');

            $table->integer('duration_seconds')->nullable();

            // Playback policy.
            $table->boolean('allow_replay')->default(false);
            $table->integer('max_replay_count')->nullable();
            $table->boolean('allow_pause')->default(true);
            $table->boolean('allow_seek')->default(false);
            $table->json('playback_speed_options')->nullable();

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};