<?php

namespace Database\Factories;

use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل ExamSnapshot.
 * یک Snapshot منجمد از آزمون می‌سازد.
 */
class ExamSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id'      => Exam::factory(),
            'generated_at' => now(),
            'is_immutable' => true,
        ];
    }
}
