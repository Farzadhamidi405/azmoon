<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مدل سوال — هسته بانک سوالات.
 * هر سوال به یک Subject تعلق دارد و چرخه تأیید مشخصی دارد.
 * گزینه‌ها بدون محدودیت تعداد هستند (بند 60.1 SRS).
 */
class Question extends Model
{
    use HasFactory, SoftDeletes;
    

    protected $table = 'questions';

    const STATUSES = [
        'draft',
        'under_review',
        'approved',
        'active',
        'inactive',
        'archived',
    ];

    const TYPES = [
        'single',
        'multi',
        'true_false',
        'short_answer',
    ];

    protected $fillable = [
        'subject_id',
        'section_name',
        'question_type',
        'question_text',
        'difficulty',
        'topic',
        'score',
        'passage_id',
        'media_id',
        'status',
        'possible_duplicate_of',
        'current_version_id',
        'created_by',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function passage(): BelongsTo
    {
        return $this->belongsTo(Passage::class, 'passage_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function possibleDuplicate(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'possible_duplicate_of');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'current_version_id');
    }

    /** گزینه‌های این سوال — بدون محدودیت تعداد */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id')
                    ->orderBy('display_order');
    }

    /** تمام نسخه‌های این سوال */
    public function versions(): HasMany
    {
        return $this->hasMany(QuestionVersion::class, 'question_id')
                    ->orderByDesc('version_number');
    }

    // ─── Scopes ──────────────────────────────────────────────

    /** فقط سوالات فعال */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** فقط سوالات تأییدشده یا فعال */
    public function scopeApproved($query)
    {
        return $query->whereIn('status', ['approved', 'active']);
    }
}