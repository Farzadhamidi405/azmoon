<?php

namespace Database\Factories;

use App\Models\Subject;
use App\Models\User;
use App\Models\UserSubjectAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSubjectAccess>
 */
class UserSubjectAccessFactory extends Factory
{
    protected $model = UserSubjectAccess::class;

    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'subject_id' => Subject::factory(),
            'granted_by' => null,
            'granted_at' => now(),
        ];
    }
}