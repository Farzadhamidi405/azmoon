<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مدل متن خواندنی (Reading Passage).
 * یک Passage می‌تواند چند سوال داشته باشد.
 */
class Passage extends Model
{
    protected $table = 'passages';

    protected $fillable = [
        'subject_id',
        'title',
        'body_text',
        'created_by',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'passage_id');
    }
}
