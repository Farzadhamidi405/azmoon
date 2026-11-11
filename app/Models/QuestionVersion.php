<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تاریخچه تغییرات سوال — نسخه‌های قبلی هرگز حذف نمی‌شوند (بند 9 SRS).
 * هر ویرایش یک نسخه جدید می‌سازد.
 */
class QuestionVersion extends Model
{
    protected $table = 'question_versions';

    protected $fillable = [
        'question_id',
        'version_number',
        'question_text',
        'question_type',
        'section_name',
        'difficulty',
        'options_snapshot',
        'passage_snapshot',
        'media_id',
        'changed_by',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'options_snapshot' => 'array',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
