<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * تعریف Pool سوالات برای هر بخش آزمون.
 * pool_size = تعداد کل سوالات موجود در Pool
 * pick_count = تعداد سوالاتی که برای هر متقاضی انتخاب می‌شود
 */
class ExamQuestionPool extends Model
{
    protected $fillable = [
        'exam_section_id',
        'pool_size',
        'pick_count',
        'randomize_options',
    ];

    protected $casts = [
        'randomize_options' => 'boolean',
    ];

    // ─── Relationships ───────────────────────────────────────

    /** این Pool به کدام بخش تعلق دارد */
    public function section()
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }
}