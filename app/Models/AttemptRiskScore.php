<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AttemptRiskScore — امتیاز ریسک محاسبه‌شده برای یک Attempt.
 * هر بار که رویداد Anti-Cheat جدیدی ثبت می‌شود، دوباره محاسبه می‌شود.
 * Risk Score فقط راهنماست — تصمیم نهایی با Reviewer انسانی است.
 * بند 21 SRS.
 */
class AttemptRiskScore extends Model
{
    use HasFactory;

    protected $table = 'attempt_risk_scores';

    protected $fillable = [
        'attempt_id',
        'score',
        'level',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'score'       => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    // آستانه‌های سطح ریسک — بند 21 SRS
    const LEVEL_LOW    = 30;
    const LEVEL_MEDIUM = 60;

    // ─── Relationships ───────────────────────────────────────

    /**
     * Attempt مربوط به این Risk Score.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * محاسبه سطح ریسک بر اساس امتیاز.
     * low: 0-30 / medium: 31-60 / high: 61+
     */
    public static function calculateLevel(int $score): string
    {
        if ($score <= self::LEVEL_LOW) {
            return 'low';
        }

        if ($score <= self::LEVEL_MEDIUM) {
            return 'medium';
        }

        return 'high';
    }
}
