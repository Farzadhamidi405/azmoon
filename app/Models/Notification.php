<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مدل Notification — اعلان‌های سیستمی برای کاربران ادمین.
 * Step 12.9.
 * notifiable_user_id = null یعنی اعلان broadcast (قابل‌مشاهده برای همه، غیرقابل mark-read از طریق API).
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'body',
        'related_type',
        'related_id',
        'is_read',
        'notifiable_user_id',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    /**
     * کاربری که این اعلان به او اختصاص دارد (در صورت broadcast بودن، null است).
     */
    public function notifiableUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notifiable_user_id');
    }

    /**
     * آیا این اعلان برای کاربر داده‌شده قابل‌مشاهده است؟ (own یا broadcast)
     */
    public function isVisibleTo(User $user): bool
    {
        return $this->notifiable_user_id === null
            || $this->notifiable_user_id === $user->id;
    }
}