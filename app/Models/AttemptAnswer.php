<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * پاسخ متقاضی به یک سوال در یک Attempt.
 * Auto Save — هر پاسخ بلافاصله ذخیره می‌شود.
 * idempotency_key از ثبت تکراری در صورت قطعی شبکه جلوگیری می‌کند.
 * Unique constraint: یک سوال در هر Attempt فقط یک رکورد پاسخ دارد.
 * بند 17 SRS و ERD V2.
 */
class AttemptAnswer extends Model
{
    use HasFactory;

    protected $table = 'attempt_answers';

    protected $fillable = [
        'attempt_id',
        'exam_snapshot_question_id',
        'selected_option_ids',
        'answer_text',
        'is_correct',
        'score_awarded',
        'time_spent_seconds',
        'changed_count',
        'idempotency_key',
        'client_timestamp',
        'server_timestamp',
        'sync_status',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'is_correct'          => 'boolean',
            'score_awarded'       => 'decimal:2',
            'time_spent_seconds'  => 'integer',
            'changed_count'       => 'integer',
            'client_timestamp'    => 'datetime',
            'server_timestamp'    => 'datetime',
            'answered_at'         => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * Attempt ای که این پاسخ به آن تعلق دارد.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /**
     * سوال Snapshot شده که این پاسخ برای آن ثبت شده.
     * محتوای سوال از ستون‌های snapshot خوانده می‌شود.
     */
    public function snapshotQuestion(): BelongsTo
    {
        return $this->belongsTo(ExamSnapshotQuestion::class, 'exam_snapshot_question_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی همگام‌سازی پاسخ با سرور.
     */
    public function isSynced(): bool
    {
        return $this->sync_status === 'synced';
    }

    /**
     * بررسی وجود تعارض در پاسخ.
     */
    public function hasConflict(): bool
    {
        return $this->sync_status === 'conflict';
    }
}