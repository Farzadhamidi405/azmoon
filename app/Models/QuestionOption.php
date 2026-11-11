<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * گزینه‌های هر سوال — بدون محدودیت تعداد (بند 60.1 SRS).
 * هر سوال می‌تواند هر تعداد گزینه داشته باشد.
 */
class QuestionOption extends Model
{
    protected $table = 'question_options';

    protected $fillable = [
        'question_id',
        'option_text',
        'is_correct',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
