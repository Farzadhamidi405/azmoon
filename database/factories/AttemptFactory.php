<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory برای مدل Attempt.
 * یک Attempt در حال اجرا می‌سازد.
 */
class AttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'candidate_id'              => Candidate::factory(),
            'exam_id'                   => Exam::factory(),
            'candidate_assignment_id'   => CandidateAssignment::factory(),
            'exam_snapshot_id'          => ExamSnapshot::factory(),
            'computer_id'               => Computer::factory(),
            'ip_address'                => $this->faker->localIpv4(),
            'browser'                   => 'Chrome',
            'device'                    => 'Desktop',
            'started_at'                => now(),
            'expires_at'                => now()->addMinutes(60),
            'last_activity_at'          => now(),
            'status'                    => 'in_progress',
            'session_token'             => Str::uuid(),
            'connection_status'         => 'connected',
            'last_heartbeat_at'         => now(),
            'answered_count'            => 0,
            'reconnect_count'           => 0,
        ];
    }

    /**
     * وضعیت completed برای Attempt.
     */
    public function completed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status'       => 'completed',
            'submitted_at' => now(),
        ]);
    }
}