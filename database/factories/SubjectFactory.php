<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        return [
            'name'       => fake()->unique()->word(),
            'code'       => strtoupper(fake()->unique()->lexify('???')),
            'is_active'  => true,
            'created_by' => null,
        ];
    }
}