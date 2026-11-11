<?php
// app/Models/EntryCard.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * کارت ورود متقاضی.
 * QR Token امن و یک‌بارمصرف است — هرگز رمز خام ذخیره نمی‌شود.
 * بند 14 SRS و ERD V2.
 */
class EntryCard extends Model
{
    use HasFactory;

    protected $table = 'entry_cards';

    protected $fillable = [
        'candidate_id',
        'qr_token',
        'qr_expires_at',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'qr_expires_at' => 'datetime',
            'printed_at'    => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * متقاضی صاحب این کارت ورود.
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی منقضی شدن QR Token.
     * Backend مرجع است — Frontend فقط نمایش می‌دهد.
     */
    public function isExpired(): bool
    {
        return $this->qr_expires_at->isPast();
    }

    /**
     * بررسی چاپ شدن کارت.
     */
    public function isPrinted(): bool
    {
        return $this->printed_at !== null;
    }
}
