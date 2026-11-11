<?php

namespace Database\Factories;

use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل ExamSnapshotQuestion.
 * یک سوال منجمدشده در Snapshot آزمون می‌سازد.
 */
class ExamSnapshotQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_snapshot_id'       => ExamSnapshot::factory(),
            'exam_section_id'        => ExamSection::factory(),
            'question_id'            => Question::factory(),
            'display_order'          => $this->faker->numberBetween(1, 50),
            'score'                  => 2,
            'question_text_snapshot' => $this->faker->sentence() . '?',
            'question_type_snapshot' => 'single',
            'options_snapshot'       => json_encode([
                ['id' => 1, 'text' => 'Option A', 'is_correct' => true],
                ['id' => 2, 'text' => 'Option B', 'is_correct' => false],
                ['id' => 3, 'text' => 'Option C', 'is_correct' => false],
                ['id' => 4, 'text' => 'Option D', 'is_correct' => false],
            ]),
            'passage_snapshot'       => null,
            'media_snapshot'         => null,
            'randomization_seed'     => null,
        ];
    }
}
