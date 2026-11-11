<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ManualOverride — عملیات دستی ادمین روی یک Attempt.
 * هر Override نیازمند دلیل است و در Audit Log ثبت می‌شود.
 * بند 27 SRS.
 */
class ManualOverride extends Model
{
    use HasFactory;

    protected $table = 'manual_overrides';

    protected $fillable = [
        'attempt_id',
        'action',
        'reason',
        'old_value',
        'new_value',
        'performed_by',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_value'    => 'array',
            'new_value'    => 'array',
            'performed_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * Attempt که این Override روی آن انجام شده.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /**
     * کاربری که این Override را انجام داده.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
