<?php
// app/Models/Lab.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * لابراتوار — شامل چند کامپیوتر و چند شفت است.
 * بند 24 SRS و ERD V2.
 */
class Lab extends Model
{
    use HasFactory;

    protected $table = 'labs';

    /** Allowed computer statuses (same values as the computers.status enum). */
    public const COMPUTER_STATUSES = ['online', 'offline', 'exam', 'maintenance', 'locked'];

    protected $fillable = [
        'name',
        'location',
    ];

    // --- Relationships ---

    /**
     * کامپیوترهای این لابراتوار.
     */
    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class, 'lab_id');
    }

    /**
     * شفت‌های این لابراتوار.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'lab_id');
    }

    /**
     * تخصیص‌های متقاضیان به این لابراتوار.
     */
    public function candidateAssignments(): HasMany
    {
        return $this->hasMany(CandidateAssignment::class, 'lab_id');
    }

    // --- Helper Methods ---

    /**
     * تعداد کامپیوترهای آنلاین این لابراتوار.
     */
    public function onlineComputersCount(): int
    {
        return $this->computers()->where('status', 'online')->count();
    }

    /**
     * تعداد کامپیوترهای آزاد برای تخصیص.
     */
    public function availableComputersCount(): int
    {
        return $this->computers()->where('status', 'online')->count();
    }

    // --- Computer counters (used by LabController index/show/computersGrid) ---

    /**
     * Relation-count definitions: total computers plus one count per status.
     * Result keys: computers_count, online_count, offline_count, exam_count, maintenance_count, locked_count.
     */
    public static function computerCounters(): array
    {
        $counters = ['computers'];
        foreach (self::COMPUTER_STATUSES as $status) {
            $counters["computers as {$status}_count"] = fn ($q) => $q->where('status', $status);
        }

        return $counters;
    }

    /**
     * Builds the status summary (total + one count per status) from an already loaded computers collection.
     */
    public static function summarizeStatuses(\Illuminate\Support\Collection $computers): array
    {
        $summary = ['total' => $computers->count()];
        foreach (self::COMPUTER_STATUSES as $status) {
            $summary[$status] = $computers->where('status', $status)->count();
        }

        return $summary;
    }}
