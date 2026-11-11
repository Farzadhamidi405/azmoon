<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سوال منجمدشده در Snapshot آزمون.
 * محتوای کامل سوال (متن، گزینه‌ها، پاسخ صحیح) در لحظهٔ Publish کپی می‌شود.
 * question_id و question_version_id فقط برای Traceability هستند —
 * هرگز برای خواندن محتوا استفاده نمی‌شوند.
 * بند 10 SRS و ERD V2.
 */
class ExamSnapshotQuestion extends Model
{
    use HasFactory;

    protected $table = 'exam_snapshot_questions';

    protected $fillable = [
        'exam_snapshot_id',
        'question_id',
        'question_version_id',
        'exam_section_id',
        'display_order',
        'score',
        'question_text_snapshot',
        'question_type_snapshot',
        'options_snapshot',
        'passage_snapshot',
        'media_snapshot',
        'randomization_seed',
    ];

    protected function casts(): array
    {
        return [
            'score'            => 'decimal:2',
            'display_order'    => 'integer',
            'options_snapshot' => 'array',
            'media_snapshot'   => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * Snapshot ای که این سوال به آن تعلق دارد.
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ExamSnapshot::class, 'exam_snapshot_id');
    }

    /**
     * سوال اصلی — فقط برای Traceability.
     * محتوا را از این رابطه نخوان؛ از ستون‌های snapshot بخوان.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    /**
     * نسخهٔ دقیق سوال — فقط برای Traceability.
     */
    public function questionVersion(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'question_version_id');
    }

    /**
     * بخش آزمون که این سوال به آن تعلق دارد.
     */
    public function examSection(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }

    /**
     * پاسخ‌های متقاضیان به این سوال.
     */
    public function attemptAnswers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class, 'exam_snapshot_question_id');
    }
}