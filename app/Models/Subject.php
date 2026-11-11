<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مدل مضمون — هر آزمون و سوال به یک مضمون تعلق دارد.
 */
class Subject extends Model
{


    use HasFactory;
    protected $table = 'subjects';

    protected $fillable = [
        'name',
        'code',
        'faculty_id',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * دانشکده‌ای که این مضمون به آن تعلق دارد.
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }

    /**
     * کاربری که این مضمون را ایجاد کرده.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * آزمون‌های مرتبط با این مضمون.
     */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'subject_id');
    }

    /**
     * دسترسی‌های کاربران به این مضمون.
     */
    public function userAccess(): HasMany
    {
        return $this->hasMany(UserSubjectAccess::class, 'subject_id');
    }

    /**
     * سوالات این مضمون — برای بررسی وابستگی هنگام حذف.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'subject_id');
    }

    /**
     * متن‌های خواندنی این مضمون — برای بررسی وابستگی هنگام حذف.
     */
    public function passages(): HasMany
    {
        return $this->hasMany(Passage::class, 'subject_id');
    }
}