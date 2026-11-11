<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Model متقاضی — کاملاً مستقل از User (کارمندان سیستم).
 * Guard جداگانه: candidate
 * بند 11 SRS و ERD V2.
 */
class Candidate extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'candidates';

    protected $fillable = [
        'candidate_code',
        'name',
        'national_id',
        'username',
        'password',
        'status',
        'checked_in_at',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'must_reset_password',
        'password_reset_token',
        'credential_expires_at',
    ];

    protected $hidden = [
        'password',
        'password_reset_token',
    ];

    protected function casts(): array
    {
        return [
            'password'              => 'hashed',
            'checked_in_at'         => 'datetime',
            'locked_until'          => 'datetime',
            'last_login_at'         => 'datetime',
            'credential_expires_at' => 'datetime',
            'must_reset_password'   => 'boolean',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * تخصیص‌های این متقاضی به آزمون‌ها.
     * یک متقاضی می‌تواند در چند آزمون شرکت کند.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CandidateAssignment::class, 'candidate_id');
    }

    /**
     * آخرین تخصیص فعال متقاضی.
     */
    public function latestAssignment(): HasOne
    {
        return $this->hasOne(CandidateAssignment::class, 'candidate_id')
                    ->latestOfMany();
    }

    /**
     * کارت‌های ورود این متقاضی.
     */
    public function entryCards(): HasMany
    {
        return $this->hasMany(EntryCard::class, 'candidate_id');
    }

    /**
     * آخرین کارت ورود فعال.
     */
    public function latestEntryCard(): HasOne
    {
        return $this->hasOne(EntryCard::class, 'candidate_id')
                    ->latestOfMany();
    }

    /**
     * تمام Attempt های این متقاضی.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class, 'candidate_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی قفل بودن حساب متقاضی.
     * Backend مرجع است — Frontend فقط نمایش می‌دهد.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null
            && $this->locked_until->isFuture();
    }

    /**
     * بررسی انقضای اعتبار رمز موقت کارت ورود.
     */
    public function isCredentialExpired(): bool
    {
        return $this->credential_expires_at !== null
            && $this->credential_expires_at->isPast();
    }
}