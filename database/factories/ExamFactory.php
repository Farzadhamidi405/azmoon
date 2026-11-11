<?php

namespace Database\Factories;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل Exam.
 * یک آزمون با وضعیت draft می‌سازد.
 */
class ExamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject_id'              => Subject::factory(),
            'title'                   => $this->faker->sentence(4),
            'description'             => $this->faker->optional()->paragraph(),
            'duration_minutes'        => 60,
            'total_marks'             => 100,
            'pass_marks'              => 60,
            'status'                  => 'draft',
            'randomize_questions'     => false,
            'randomize_options'       => false,
            'allow_back_navigation'   => true,
            'show_result_immediately' => false,
            'created_by'              => User::factory(),
        ];
    }

    /**
     * وضعیت published برای امتحان.
     */
    public function published(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'       => 'published',
            'published_at' => now(),
        ]);
    }
}