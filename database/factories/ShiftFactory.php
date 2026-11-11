<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\Lab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل Shift.
 * یک شفت زمانی برای آزمون می‌سازد.
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addHours(1);
        return [
            'exam_id'    => Exam::factory(),
            'name'       => 'Shift ' . $this->faker->numberBetween(1, 5),
            'start_time' => $start,
            'end_time'   => $start->copy()->addHours(2),
            'lab_id'     => Lab::factory(),
            'capacity'   => 30,
        ];
    }
}