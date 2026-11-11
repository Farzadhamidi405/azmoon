<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\Lab;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل CandidateAssignment.
 * تخصیص یک متقاضی به آزمون، شفت، لابراتوار و کامپیوتر.
 */
class CandidateAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'candidate_id' => Candidate::factory(),
            'exam_id'      => Exam::factory(),
            'shift_id'     => Shift::factory(),
            'lab_id'       => Lab::factory(),
            'computer_id'  => Computer::factory(),
            'assigned_at'  => now(),
        ];
    }
}