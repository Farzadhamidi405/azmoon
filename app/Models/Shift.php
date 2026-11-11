<?php
// app/Models/Shift.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * شفت زمانی — هر شفت به یک آزمون و یک لابراتوار تعلق دارد.
 * بند 25 SRS و ERD V2.
 */
class Shift extends Model
{
    use HasFactory;

    protected $table = 'shifts';

    protected $fillable = [
        'exam_id',
        'lab_id',
        'name',
        'start_time',
        'end_time',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time'   => 'datetime',
            'capacity'   => 'integer',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * آزمونی که این شفت به آن تعلق دارد.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * لابراتواری که این شفت در آن برگزار می‌شود.
     */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class, 'lab_id');
    }

    /**
     * تخصیص‌های متقاضیان در این شفت.
     */
    public function candidateAssignments(): HasMany
    {
        return $this->hasMany(CandidateAssignment::class, 'shift_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * تعداد متقاضیان تخصیص‌یافته به این شفت.
     */
    public function assignedCount(): int
    {
        return $this->candidateAssignments()->count();
    }

    /**
     * ظرفیت باقیمانده این شفت.
     */
    public function remainingCapacity(): int
    {
        return $this->capacity - $this->assignedCount();
    }

    /**
     * بررسی پر بودن ظرفیت شفت.
     */
    public function isFull(): bool
    {
        return $this->remainingCapacity() <= 0;
    }
}