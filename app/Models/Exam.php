<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model آزمون — هسته اصلی سیستم.
 * هر آزمون به یک Subject وصل است و چرخه عمر مشخصی دارد.
 */
class Exam extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'subject_id',
        'title',
        'description',
        'duration_minutes',
        'total_marks',
        'pass_marks',
        'status',
        'randomize_questions',
        'randomize_options',
        'allow_back_navigation',
        'show_result_immediately',
        'default_calendar',
        'created_by',
        'approved_by',
        'published_at',
    ];

    protected $casts = [
        'total_marks'             => 'decimal:2',
        'pass_marks'              => 'decimal:2',
        'randomize_questions'     => 'boolean',
        'randomize_options'       => 'boolean',
        'allow_back_navigation'   => 'boolean',
        'show_result_immediately' => 'boolean',
        'published_at'            => 'datetime',
    ];

    // وضعیت‌های مجاز چرخه عمر آزمون
    const STATUSES = [
        'draft',
        'under_review',
        'approved',
        'scheduled',
        'published',
        'running',
        'completed',
        'reviewed',
        'archived',
    ];

    // ─── Relationships ───────────────────────────────────────

    /** آزمون به یک Subject تعلق دارد */
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /** کاربری که آزمون را ایجاد کرده */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** کاربری که آزمون را تأیید کرده */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** بخش‌های آزمون */
    public function sections()
    {
        return $this->hasMany(ExamSection::class)->orderBy('display_order');
    }

    /** Snapshot آزمون — منجمد در لحظه Publish */
    public function snapshots()
    {
        return $this->hasMany(ExamSnapshot::class);
    }

    /** آخرین Snapshot */
    public function latestSnapshot()
    {
        return $this->hasOne(ExamSnapshot::class)->latestOfMany();
    }

    /** شفت‌های این آزمون */
    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    /** فقط آزمون‌های منتشرشده */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /** فقط آزمون‌های فعال (در حال اجرا) */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }
}