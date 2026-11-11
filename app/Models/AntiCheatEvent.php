<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AntiCheatEvent — رویداد مشکوک ثبت‌شده در جلسه امتحان.
 * اصل مهم: هیچ رویدادی به‌تنهایی به معنای تقلب قطعی نیست.
 * سیستم فقط ثبت می‌کند و Risk Score تولید می‌کند.
 * تصمیم نهایی همیشه با Reviewer انسانی است.
 * بند 20 و 21 SRS.
 */
class AntiCheatEvent extends Model
{
    use HasFactory;

    protected $table = 'anti_cheat_events';

    protected $fillable = [
        'attempt_id',
        'event_type',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta'        => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    // وزن هر نوع رویداد برای محاسبه Risk Score — بند 21 SRS
    const EVENT_WEIGHTS = [
        'fullscreen_exit'    => 2,
        'tab_switch'         => 3,
        'window_blur'        => 1,
        'copy'               => 3,
        'paste'              => 3,
        'right_click'        => 1,
        'keyboard_shortcut'  => 2,
        'print_attempt'      => 5,
        'network_disconnect' => 1,
        'network_reconnect'  => 0,
        'multiple_login'     => 10,
        'ip_change'          => 5,
        'browser_change'     => 5,
        'computer_change'    => 3,
        'session_transfer'   => 0,
        'idle_time'          => 1,
    ];

    // ─── Relationships ───────────────────────────────────────

    /**
     * Attempt مربوط به این رویداد.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }
}
