<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل Lab.
 * یک لابراتوار با نام و مکان تصادفی می‌سازد.
 */
class LabFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'     => 'Lab ' . $this->faker->unique()->numberBetween(1, 99),
            'location' => $this->faker->optional()->word(),
        ];
    }
}