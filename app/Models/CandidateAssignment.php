<?php
// app/Models/CandidateAssignment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تخصیص متقاضی به آزمون، شفت، لابراتوار و کامپیوتر.
 * Unique constraint: یک متقاضی فقط یک بار در هر آزمون ثبت می‌شود.
 * بند 11 SRS، بند 59.1 (Auto-Assign) و ERD V2.
 */
class CandidateAssignment extends Model
{
    use HasFactory;

    protected $table = 'candidate_assignments';

    protected $fillable = [
        'candidate_id',
        'exam_id',
        'shift_id',
        'lab_id',
        'computer_id',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────

    /**
     * متقاضی این تخصیص.
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /**
     * آزمون این تخصیص.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * شفت این تخصیص.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * لابراتوار این تخصیص.
     */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class, 'lab_id');
    }

    /**
     * کامپیوتر تخصیص‌یافته — nullable تا لحظهٔ Check-in یا Auto-Assign.
     */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'computer_id');
    }

    /**
     * کاربر ادمینی که این تخصیص را انجام داده.
     * Null یعنی سیستم Auto-Assign کرده است.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Attempt های مربوط به این تخصیص — Section 8.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class, 'candidate_assignment_id');
    }

    // ─── Helper Methods ──────────────────────────────────────

    /**
     * بررسی اینکه آیا کامپیوتر تخصیص یافته یا خیر.
     */
    public function hasComputer(): bool
    {
        return $this->computer_id !== null;
    }

    /**
     * بررسی Auto-Assign بودن (assigned_by = null).
     */
    public function isAutoAssigned(): bool
    {
        return $this->assigned_by === null;
    }
}