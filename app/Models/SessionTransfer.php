<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SessionTransfer — انتقال جلسه امتحان از یک کامپیوتر به کامپیوتر دیگر.
 * پاسخ‌ها و زمان باقیمانده حفظ می‌شود.
 * در Audit Log ثبت می‌شود — بند 22 SRS.
 */
class SessionTransfer extends Model
{
    use HasFactory;

    protected $table = 'session_transfers';

    protected $fillable = [
        'attempt_id',
        'from_computer_id',
        'to_computer_id',
        'reason',
        'transferred_by',
        'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * Attempt که این انتقال روی آن انجام شده.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }

    /**
     * کامپیوتر مبدأ.
     */
    public function fromComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'from_computer_id');
    }

    /**
     * کامپیوتر مقصد.
     */
    public function toComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'to_computer_id');
    }

    /**
     * کاربری که انتقال را انجام داده.
     */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}