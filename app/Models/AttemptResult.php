<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نتیجهٔ کلی یک Attempt — رابطهٔ One-to-One با Attempt.
 * جدا از attempts برای گزارش‌گیری تمیز و امکان بازمحاسبهٔ دستی.
 * calculated_by = manual یعنی Reviewer نمره را دستی اصلاح کرده.
 * بند 28 SRS و ERD V2.
 */
class AttemptResult extends Model
{
    use HasFactory;

    protected $table = 'attempt_results';

    protected $fillable = [
        'attempt_id',
        'total_score',
        'percentage',
        'pass_fail',
        'calculated_by',
        'recalculated_by',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_score'    => 'decimal:2',
            'percentage'     => 'decimal:2',
            'calculated_at'  => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * Attempt ای که این نتیجه به آن تعلق دارد — One-to-One.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /**
     * کاربری که نمره را دستی بازمحاسبه کرده.
     * Null یعنی سیستم به‌صورت خودکار محاسبه کرده.
     */
    public function recalculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recalculated_by');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی قبول بودن متقاضی.
     */
    public function isPassed(): bool
    {
        return $this->pass_fail === 'pass';
    }

    /**
     * بررسی دستی بودن محاسبهٔ نمره.
     */
    public function isManuallyCalculated(): bool
    {
        return $this->calculated_by === 'manual';
    }
}