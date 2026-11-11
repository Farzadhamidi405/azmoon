<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role_id',
        'is_active',
        'two_factor_enabled',
        'two_factor_secret',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'password'           => 'hashed',
            'is_active'          => 'boolean',
            'two_factor_enabled' => 'boolean',
            'locked_until'       => 'datetime',
            'last_login_at'      => 'datetime',
            'email_verified_at'  => 'datetime',
        ];
    }

    /**
     * Role attached to this staff user.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Subjects this user can access — relevant for Question Manager / Reviewer.
     */
    public function subjectAccess(): HasMany
    {
        return $this->hasMany(UserSubjectAccess::class, 'user_id');
    }

    /**
     * Check if this staff account is currently locked.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null
            && $this->locked_until->isFuture();
    }

    /**
     * Check whether this user has a specific permission via their role.
     * Format: "module.action" — example: "questions.create"
     * Super Admin always has full access without checking the permissions table.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->role && $this->role->name === 'Super Admin') {
            return true;
        }

        [$module, $action] = explode('.', $permission);

        return $this->role
            && $this->role->permissions()
                ->where('module', $module)
                ->where('action', $action)
                ->exists();
    }

    /**
     * بررسی دسترسی کاربر به یک مضمون خاص.
     * Super Admin و Exam Administrator به همه مضامین دسترسی دارند.
     * سایر نقش‌ها فقط به مضامین تخصیص‌یافته دسترسی دارند.
     */
    public function hasSubjectAccess(?int $subjectId): bool
    {
        // اگر subject_id خالی باشد، دسترسی داده می‌شود
        if ($subjectId === null) {
            return true;
        }

        // Super Admin و Exam Administrator به همه مضامین دسترسی دارند
        if (in_array($this->role?->name, ['Super Admin', 'Exam Administrator'])) {
            return true;
        }

        // بررسی از طریق جدول user_subject_access
        return $this->subjectAccess()
            ->where('subject_id', $subjectId)
            ->exists();
    }
}
