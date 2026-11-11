<?php
// app/Models/Computer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * کامپیوتر فیزیکی در لابراتوار.
 * وضعیت‌های مجاز: online, offline, exam, maintenance, locked
 * بند 23 SRS و ERD V2.
 */
class Computer extends Model
{
    use HasFactory;

    protected $table = 'computers';

    protected $fillable = [
        'lab_id',
        'computer_code',
        'hostname',
        'ip_address',
        'mac_address',
        'os',
        'browser',
        'agent_version',
        'status',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * لابراتواری که این کامپیوتر در آن قرار دارد.
     */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class, 'lab_id');
    }

    /**
     * تخصیص‌هایی که این کامپیوتر به آن‌ها اختصاص یافته.
     */
    public function candidateAssignments(): HasMany
    {
        return $this->hasMany(CandidateAssignment::class, 'computer_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی آزاد بودن کامپیوتر برای تخصیص.
     * کامپیوتر باید online باشد.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'online';
    }

    /**
     * بررسی اینکه آیا کامپیوتر در حال امتحان است.
     */
    public function isInExam(): bool
    {
        return $this->status === 'exam';
    }
}