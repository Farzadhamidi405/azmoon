<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Factory برای مدل Candidate.
 * یک متقاضی با وضعیت present می‌سازد.
 */
class CandidateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'candidate_code'       => 'KND' . $this->faker->unique()->numerify('###'),
            'name'                 => $this->faker->name(),
            'national_id'          => $this->faker->optional()->numerify('##########'),
            'username'             => $this->faker->unique()->userName(),
            'password'             => Hash::make('password'),
            'status'               => 'present',
            'failed_login_attempts'=> 0,
            'must_reset_password'  => false,
        ];
    }
}