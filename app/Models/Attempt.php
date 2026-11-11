<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Attempt — جلسهٔ امتحان یک متقاضی.
 * Backend مرجع اصلی برای Timer، وضعیت، و پاسخ‌هاست.
 * Frontend فقط نمایش می‌دهد — هرگز مرجع تصمیم‌گیری نیست.
 * بند 15، 16 SRS و ERD V2.
 */
class Attempt extends Model
{
    use HasFactory;

    protected $table = 'attempts';

    protected $fillable = [
        'candidate_id',
        'exam_id',
        'candidate_assignment_id',
        'exam_snapshot_id',
        'computer_id',
        'ip_address',
        'browser',
        'device',
        'started_at',
        'expires_at',
        'last_activity_at',
        'submitted_at',
        'status',
        'session_token',
        'connection_status',
        'last_heartbeat_at',
        'disconnected_at',
        'reconnect_count',
        'answered_count',
        'correct_count',
        'wrong_count',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'started_at'        => 'datetime',
            'expires_at'        => 'datetime',
            'last_activity_at'  => 'datetime',
            'submitted_at'      => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'disconnected_at'   => 'datetime',
            'reconnect_count'   => 'integer',
            'answered_count'    => 'integer',
            'correct_count'     => 'integer',
            'wrong_count'       => 'integer',
            'score'             => 'decimal:2',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * متقاضی صاحب این Attempt.
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /**
     * آزمون مربوط به این Attempt.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * تخصیص متقاضی که این Attempt از آن ایجاد شده.
     */
    public function candidateAssignment(): BelongsTo
    {
        return $this->belongsTo(CandidateAssignment::class, 'candidate_assignment_id');
    }

    /**
     * Snapshot آزمون — محتوای سوالات از اینجا خوانده می‌شود.
     * هرگز از Question Bank زنده استفاده نمی‌شود.
     */
    public function examSnapshot(): BelongsTo
    {
        return $this->belongsTo(ExamSnapshot::class, 'exam_snapshot_id');
    }

    /**
     * کامپیوتری که Attempt روی آن در حال اجراست.
     */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'computer_id');
    }

    /**
     * پاسخ‌های ثبت‌شدهٔ این Attempt.
     * Auto Save — هر پاسخ بلافاصله ذخیره می‌شود.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class, 'attempt_id');
    }

    /**
     * نتایج تفکیکی بر اساس بخش‌های آزمون.
     */
    public function sectionResults(): HasMany
    {
        return $this->hasMany(AttemptSectionResult::class, 'attempt_id');
    }

    /**
     * نتیجهٔ کلی این Attempt — One-to-One.
     */
    public function result(): HasOne
    {
        return $this->hasOne(AttemptResult::class, 'attempt_id');
    }

    /**
     * رویدادهای Anti-Cheat ثبت‌شده برای این Attempt.
     * هر رویداد به‌تنهایی به معنای تقلب قطعی نیست.
     * بند 20 SRS.
     */
    public function antiCheatEvents(): HasMany
    {
        return $this->hasMany(AntiCheatEvent::class, 'attempt_id');
    }

    /**
     * آخرین Risk Score محاسبه‌شده برای این Attempt.
     * بند 21 SRS.
     */
    public function riskScore(): HasOne
    {
        return $this->hasOne(AttemptRiskScore::class, 'attempt_id')
                    ->latestOfMany('computed_at');
    }

    /**
     * تمام Risk Score های محاسبه‌شده — برای تاریخچه.
     */
    public function riskScores(): HasMany
    {
        return $this->hasMany(AttemptRiskScore::class, 'attempt_id');
    }

    /**
     * انتقال‌های Session از این Attempt.
     * TODO: فعال می‌شود بعد از ساخت SessionTransfer Model
     */
    public function sessionTransfers(): HasMany
    {
        return $this->hasMany(SessionTransfer::class, 'attempt_id');
    }

    

    /**
     * Manual Override هایی که روی این Attempt انجام شده.
     * TODO: فعال می‌شود بعد از ساخت ManualOverride Model
     */
    public function manualOverrides(): HasMany
    {
        return $this->hasMany(ManualOverride::class, 'attempt_id');
    }


    
    /**
     * حوادث مرتبط با این Attempt.
     * TODO: فعال می‌شود بعد از ساخت Incident Model
     */
    // public function incidents(): HasMany
    // {
    //     return $this->hasMany(Incident::class, 'attempt_id');
    // }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی فعال بودن Attempt.
     * Timer Backend مرجع است — نه Frontend.
     */
    public function isActive(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * بررسی اتمام زمان Attempt.
     * expires_at همیشه از Backend محاسبه می‌شود.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * بررسی قطع اتصال متقاضی.
     */
    public function isDisconnected(): bool
    {
        return $this->connection_status === 'disconnected';
    }

    /**
     * زمان باقیمانده به ثانیه — Backend مرجع است.
     * اگر صفر باشد، زمان تمام شده.
     */
    public function remainingSeconds(): int
    {
        return max(0, now()->diffInSeconds($this->expires_at, false));
    }
}
