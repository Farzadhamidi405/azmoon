<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل Question.
 * یک سوال با وضعیت draft می‌سازد.
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'subject_id'    => Subject::factory(),
            'section_name'  => 'Grammar',
            'question_type' => 'single',
            'question_text' => $this->faker->sentence(),
            'difficulty'    => $this->faker->randomElement(['easy', 'medium', 'hard']),
            'score'         => 2,
            'status'        => 'draft',
            'created_by'    => User::factory(),
        ];
    }
}
