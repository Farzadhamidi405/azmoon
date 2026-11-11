<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * بخش‌های هر آزمون — مثلاً Grammar، Reading، Listening.
 * نام بخش آزاد است (string) تا برای مضامین غیرزبانی هم کار کند.
 */
class ExamSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'name',
        'question_count',
        'total_marks',
        'display_order',
    ];

    protected $casts = [
        'total_marks' => 'decimal:2',
    ];

    // ─── Relationships ───────────────────────────────────────

    /** این بخش به کدام آزمون تعلق دارد */
    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    /** Question Pool این بخش */
    public function questionPool()
    {
        return $this->hasOne(ExamQuestionPool::class);
    }
}