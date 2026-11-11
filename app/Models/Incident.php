<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incident — حادثه یا مشکل فنی در جلسه امتحان.
 * هر حادثه می‌تواند به متقاضی، کامپیوتر، یا Attempt مرتبط باشد.
 * بند 26 SRS.
 */
class Incident extends Model
{
    use HasFactory;

    protected $table = 'incidents';

    protected $attributes = [
        'status' => 'open',
    ];

    protected $fillable = [
        'candidate_id',
        'computer_id',
        'attempt_id',
        'problem_description',
        'action_taken',
        'responsible_user_id',
        'status',
        'occurred_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * متقاضی مرتبط با این حادثه.
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /**
     * کامپیوتر مرتبط با این حادثه.
     */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'computer_id');
    }

    /**
     * Attempt مرتبط با این حادثه.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /**
     * کاربر مسئول رسیدگی به این حادثه.
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی حل‌شدن حادثه.
     */
    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }


    
}
