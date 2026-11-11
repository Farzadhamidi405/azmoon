<?php

namespace Database\Factories;

use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل ExamSection.
 * یک بخش از آزمون می‌سازد.
 */
class ExamSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id'        => Exam::factory(),
            'name'           => $this->faker->randomElement(['Grammar', 'Vocabulary', 'Reading', 'Listening']),
            'question_count' => 10,
            'total_marks'    => 20,
            'display_order'  => $this->faker->numberBetween(1, 10),
        ];
    }
}