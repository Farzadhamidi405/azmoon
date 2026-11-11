<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نتیجهٔ تفکیکی یک Attempt بر اساس بخش‌های آزمون.
 * برای گزارش‌گیری دقیق بر اساس Section استفاده می‌شود.
 * Unique constraint: یک بخش در هر Attempt فقط یک رکورد نتیجه دارد.
 * بند 29 SRS و ERD V2.
 */
class AttemptSectionResult extends Model
{
    use HasFactory;

    protected $table = 'attempt_section_results';

    protected $fillable = [
        'attempt_id',
        'exam_section_id',
        'correct_count',
        'wrong_count',
        'unanswered_count',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'correct_count'    => 'integer',
            'wrong_count'      => 'integer',
            'unanswered_count' => 'integer',
            'score'            => 'decimal:2',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * Attempt ای که این نتیجه به آن تعلق دارد.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /**
     * بخش آزمون که این نتیجه برای آن محاسبه شده.
     */
    public function examSection(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }
}