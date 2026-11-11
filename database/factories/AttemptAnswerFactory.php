<?php

namespace Database\Factories;

use App\Models\AttemptAnswer;
use App\Models\Attempt;
use App\Models\ExamSnapshotQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory برای AttemptAnswer.
 * برای تست‌های Result و Answer استفاده می‌شود.
 */
class AttemptAnswerFactory extends Factory
{
    protected $model = AttemptAnswer::class;

    public function definition(): array
    {
        return [
            'attempt_id'                => Attempt::factory(),
            'exam_snapshot_question_id' => ExamSnapshotQuestion::factory(),
            'selected_option_ids'       => [1],
            'answer_text'               => null,
            'is_correct'                => null,
            'score_awarded'             => null,
            'time_spent_seconds'        => $this->faker->numberBetween(5, 120),
            'changed_count'             => 0,
            'idempotency_key'           => Str::uuid()->toString(),
            'client_timestamp'          => null,
            'server_timestamp'          => now(),
            'sync_status'               => 'synced',
            'answered_at'               => now(),
        ];
    }
}
