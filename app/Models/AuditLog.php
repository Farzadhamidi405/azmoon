<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog — لاگ غیرقابل تغییر تمام عملیات حساس سیستم.
 * این Model عمداً HasFactory ندارد — نباید در تست ساخته شود.
 * فقط INSERT مجاز است — بند 33 SRS.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    // updated_at نداریم — audit_logs فقط created_at دارد
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'role',
        'action',
        'module',
        'target_type',
        'target_id',
        'old_value',
        'new_value',
        'ip_address',
        'device',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_value'  => 'array',
            'new_value'  => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * کاربری که این عملیات را انجام داده.
     * Nullable — بعضی عملیات توسط System Job انجام می‌شوند.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
