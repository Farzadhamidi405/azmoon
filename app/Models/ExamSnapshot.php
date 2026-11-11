<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Snapshot آزمون — منجمدشده در لحظهٔ Publish.
 * محتوای Snapshot هرگز پس از تولید ویرایش نمی‌شود (is_immutable = true).
 * نتایج همیشه از روی همین Snapshot محاسبه می‌شوند، نه از Question Bank.
 * بند 10 SRS و ERD V2.
 */
class ExamSnapshot extends Model
{
    use HasFactory;

    protected $table = 'exam_snapshots';

    protected $fillable = [
        'exam_id',
        'generated_at',
        'is_immutable',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'is_immutable' => 'boolean',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * آزمونی که این Snapshot از آن گرفته شده.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * سوالات منجمدشده در این Snapshot.
     * محتوا از ستون‌های snapshot خوانده می‌شود، نه از Question Bank.
     */
    public function snapshotQuestions(): HasMany
    {
        return $this->hasMany(ExamSnapshotQuestion::class, 'exam_snapshot_id')
                    ->orderBy('display_order');
    }

    /**
     * Attempt هایی که از این Snapshot استفاده می‌کنند.
     * TODO: فعال می‌شود بعد از ساخت Attempt Model در Stage 6.2
     */
    // public function attempts(): HasMany
    // {
    //     return $this->hasMany(Attempt::class, 'exam_snapshot_id');
    // }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی immutable بودن Snapshot.
     * Snapshot منتشرشده هرگز نباید ویرایش شود.
     */
    public function isImmutable(): bool
    {
        return $this->is_immutable === true;
    }
}