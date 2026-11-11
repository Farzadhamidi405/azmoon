<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دانشکده — گروه‌بندی مضامین.
 * برخی مضامین عمومی ممکن است به هیچ دانشکده‌ای تعلق نداشته باشند (nullable).
 * بند 60.2 SRS و ERD V2.
 */
class Faculty extends Model
{
    use HasFactory;

    protected $table = 'faculties';

    protected $fillable = [
        'name',
        'code',
    ];

    // ─── Relationships ───────────────────────────────────────

    /**
     * مضامینی که به این دانشکده تعلق دارند.
     * برخی مضامین faculty_id = null دارند (مضامین عمومی).
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'faculty_id');
    }
}