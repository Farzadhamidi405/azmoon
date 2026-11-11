<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\ExamSnapshotQuestion;
use App\Models\Lab;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست Results + Statistics.
 * بند 28، 29، 30، 31 SRS.
 */
class ResultTest extends TestCase
{
    use RefreshDatabase;

    // ─── Setup ────────────────────────────────────────────────

    /**
     * ساخت یک Attempt تکمیل‌شده با پاسخ‌های صحیح.
     */
    private function makeCompletedAttempt(): array
    {
        $lab      = Lab::factory()->create();
        $computer = Computer::factory()->create(['lab_id' => $lab->id]);
        $exam     = Exam::factory()->create([
            'status'           => 'published',
            'duration_minutes' => 60,
            'total_marks'      => 10,
            'pass_marks'       => 6,
        ]);
        $shift      = Shift::factory()->create(['exam_id' => $exam->id, 'lab_id' => $lab->id]);
        $candidate  = Candidate::factory()->create(['status' => 'present']);
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $lab->id,
            'computer_id'  => $computer->id,
        ]);
        $snapshot = ExamSnapshot::factory()->create(['exam_id' => $exam->id]);
        $section  = ExamSection::factory()->create([
            'exam_id'      => $exam->id,
            'total_marks'  => 10,
        ]);

        // ساخت سوال با گزینه‌های مشخص در options_snapshot
        $question = ExamSnapshotQuestion::factory()->create([
            'exam_snapshot_id' => $snapshot->id,
            'exam_section_id'  => $section->id,
            'score'            => 10,
            'options_snapshot' => [
                ['id' => 1, 'option_text' => 'A', 'is_correct' => true],
                ['id' => 2, 'option_text' => 'B', 'is_correct' => false],
            ],
        ]);

        $attempt = Attempt::factory()->create([
            'candidate_id'            => $candidate->id,
            'exam_id'                 => $exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id'        => $snapshot->id,
            'computer_id'             => $computer->id,
            'status'                  => 'completed',
            'started_at'              => now()->subMinutes(30),
            'submitted_at'            => now(),
            'expires_at'              => now()->addMinutes(30),
        ]);

        return compact('attempt', 'candidate', 'exam', 'question', 'section');
    }

    // ─── Result Calculation ───────────────────────────────────

    #[Test]
    public function result_is_calculated_after_submit(): void
    {
        $data     = $this->makeCompletedAttempt();
        $attempt  = $data['attempt'];
        $question = $data['question'];

        // ثبت پاسخ صحیح
        AttemptAnswer::factory()->create([
            'attempt_id'                => $attempt->id,
            'exam_snapshot_question_id' => $question->id,
            'selected_option_ids'       => [1],
        ]);

        $service = app(\App\Services\ResultService::class);
        $result  = $service->calculate($attempt);

        $this->assertEquals(10, $result->total_score);
        $this->assertEquals(100, $result->percentage);
        $this->assertEquals('pass', $result->pass_fail);
    }

    #[Test]
    public function wrong_answer_gives_zero_score(): void
    {
        $data     = $this->makeCompletedAttempt();
        $attempt  = $data['attempt'];
        $question = $data['question'];

        // ثبت پاسخ غلط
        AttemptAnswer::factory()->create([
            'attempt_id'                => $attempt->id,
            'exam_snapshot_question_id' => $question->id,
            'selected_option_ids'       => [2],
        ]);

        $service = app(\App\Services\ResultService::class);
        $result  = $service->calculate($attempt);

        $this->assertEquals(0, $result->total_score);
        $this->assertEquals('fail', $result->pass_fail);
    }

    #[Test]
    public function unanswered_question_gives_zero_score(): void
    {
        $data    = $this->makeCompletedAttempt();
        $attempt = $data['attempt'];

        // بدون هیچ پاسخی
        $service = app(\App\Services\ResultService::class);
        $result  = $service->calculate($attempt);

        $this->assertEquals(0, $result->total_score);
        $this->assertEquals('fail', $result->pass_fail);
    }

    // ─── Admin Endpoints ──────────────────────────────────────

    #[Test]
    public function admin_can_get_attempt_result(): void
    {
        $data    = $this->makeCompletedAttempt();
        $attempt = $data['attempt'];
        $admin   = User::factory()->create();

        $service = app(\App\Services\ResultService::class);
        $service->calculate($attempt);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/result");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'attempt_id',
                    'candidate',
                    'exam',
                    'result',
                    'section_results',
                    'answers',
                ],
            ]);
    }

    #[Test]
    public function admin_can_get_exam_results(): void
    {
        $data  = $this->makeCompletedAttempt();
        $exam  = $data['exam'];
        $admin = User::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/exams/{$exam->id}/results");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    #[Test]
    public function admin_can_get_exam_statistics(): void
    {
        $data    = $this->makeCompletedAttempt();
        $exam    = $data['exam'];
        $attempt = $data['attempt'];
        $admin   = User::factory()->create();

        $service = app(\App\Services\ResultService::class);
        $service->calculate($attempt);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/exams/{$exam->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'exam_id',
                    'total',
                    'passed',
                    'failed',
                    'pass_rate',
                    'average',
                    'highest',
                    'lowest',
                ],
            ]);
    }

    #[Test]
    public function admin_can_get_question_analytics(): void
    {
        $data  = $this->makeCompletedAttempt();
        $exam  = $data['exam'];
        $admin = User::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/exams/{$exam->id}/question-analytics");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }
}
