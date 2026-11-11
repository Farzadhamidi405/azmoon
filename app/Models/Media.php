<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مدل فایل صوتی/ویدیویی برای سوالات Listening.
 * شامل تنظیمات Playback Policy است.
 */
class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'type',
        'file_path',
        'duration_seconds',
        'allow_replay',
        'max_replay_count',
        'allow_pause',
        'allow_seek',
        'playback_speed_options',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'allow_replay'           => 'boolean',
            'allow_pause'            => 'boolean',
            'allow_seek'             => 'boolean',
            'playback_speed_options' => 'array',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'media_id');
    }
}
