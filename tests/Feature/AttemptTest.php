<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSnapshot;
use App\Models\ExamSnapshotQuestion;
use App\Models\ExamSection;
use App\Models\Lab;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست موتور اصلی امتحان — AttemptController.
 * بند 15، 16، 17 SRS.
 */
class AttemptTest extends TestCase
{
    use RefreshDatabase;

    // ─── Setup ────────────────────────────────────────────────

    /**
     * ساخت داده‌های پایه برای تست.
     * یک Candidate با Assignment و Snapshot آماده می‌کند.
     */
    private function makeCandidate(): array
    {
        // ساخت Lab و Computer
        $lab = Lab::factory()->create();
        $computer = Computer::factory()->create([
            'lab_id' => $lab->id,
            'status' => 'online',
        ]);

        // ساخت Exam
        $exam = Exam::factory()->create([
            'status'           => 'published',
            'duration_minutes' => 60,
        ]);

        // ساخت Shift
        $shift = Shift::factory()->create([
            'exam_id' => $exam->id,
            'lab_id'  => $lab->id,
        ]);

        // ساخت Candidate
        $candidate = Candidate::factory()->create([
            'status' => 'present',
        ]);

        // ساخت Assignment
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $lab->id,
            'computer_id'  => $computer->id,
        ]);

        // ساخت Snapshot
        $snapshot = ExamSnapshot::factory()->create([
            'exam_id'      => $exam->id,
            'is_immutable' => true,
        ]);

        // ساخت Section
        $section = ExamSection::factory()->create([
            'exam_id' => $exam->id,
        ]);

        // ساخت سوالات Snapshot
        ExamSnapshotQuestion::factory()->count(3)->create([
            'exam_snapshot_id' => $snapshot->id,
            'exam_section_id'  => $section->id,
        ]);

        return compact('candidate', 'exam', 'assignment', 'snapshot', 'computer');
    }

    // ─── Start ────────────────────────────────────────────────

    #[Test]
    public function candidate_can_start_exam(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'attempt' => [
                        'id',
                        'status',
                        'remaining_seconds',
                        'session_token',
                    ],
                    'questions',
                    'answers',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attempt.status', 'in_progress');
    }

    #[Test]
    public function start_returns_existing_attempt_if_active(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        // شروع اول
        $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        // شروع دوم — باید همان Attempt قبلی برگردد
        $response = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(200)
            ->assertJsonPath('data.attempt.is_resume', true);

        // فقط یک Attempt باید در DB باشد
        $this->assertDatabaseCount('attempts', 1);
    }

    #[Test]
    public function candidate_cannot_start_without_assignment(): void
    {
        $candidate = Candidate::factory()->create();

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function completed_candidate_cannot_start_again(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $exam = $data['exam'];

        // ساخت Attempt تکمیل‌شده
        Attempt::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $exam->id,
            'status'       => 'completed',
        ]);

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ─── State ────────────────────────────────────────────────

    #[Test]
    public function candidate_can_get_attempt_state(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        // شروع امتحان
        $startResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId = $startResponse->json('data.attempt.id');

        $response = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/candidate/attempts/{$attemptId}/state");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'attempt' => ['remaining_seconds'],
                    'questions',
                    'answers',
                ],
            ]);
    }

    #[Test]
    public function candidate_cannot_access_another_candidates_attempt(): void
    {
        $data1 = $this->makeCandidate();
        $data2 = $this->makeCandidate();

        // candidate1 شروع می‌کند
        $startResponse = $this->actingAs($data1['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId = $startResponse->json('data.attempt.id');

        // candidate2 سعی می‌کند state همان Attempt را ببیند
        $response = $this->actingAs($data2['candidate'], 'candidate')
            ->getJson("/api/v1/candidate/attempts/{$attemptId}/state");

        $response->assertStatus(403);
    }

    // ─── Save Answer ──────────────────────────────────────────

    #[Test]
    public function candidate_can_save_answer(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        // شروع امتحان
        $startResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId  = $startResponse->json('data.attempt.id');
        $questionId = $startResponse->json('data.questions.0.id');

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attemptId}/answer", [
                'exam_snapshot_question_id' => $questionId,
                'selected_option_ids'       => [1],
                'time_spent_seconds'        => 30,
                'idempotency_key'           => 'test-key-001',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'پاسخ ذخیره شد.');

        $this->assertDatabaseHas('attempt_answers', [
            'attempt_id'      => $attemptId,
            'idempotency_key' => 'test-key-001',
        ]);
    }

    #[Test]
    public function duplicate_answer_with_same_idempotency_key_is_ignored(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        $startResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId  = $startResponse->json('data.attempt.id');
        $questionId = $startResponse->json('data.questions.0.id');

        $payload = [
            'exam_snapshot_question_id' => $questionId,
            'selected_option_ids'       => [1],
            'time_spent_seconds'        => 30,
            'idempotency_key'           => 'same-key-999',
        ];

        // ارسال اول
        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attemptId}/answer", $payload);

        // ارسال دوم با همان key
        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attemptId}/answer", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // فقط یک رکورد باید در DB باشد
        $this->assertDatabaseCount('attempt_answers', 1);
    }

    // ─── Heartbeat ────────────────────────────────────────────

    #[Test]
    public function candidate_can_send_heartbeat(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        $startResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId = $startResponse->json('data.attempt.id');

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attemptId}/heartbeat");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'remaining_seconds',
                'server_time',
            ]);
    }

    // ─── Submit ───────────────────────────────────────────────

    #[Test]
    public function candidate_can_submit_exam(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        $startResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId = $startResponse->json('data.attempt.id');

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attemptId}/submit");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'امتحان با موفقیت ارسال شد.');

        $this->assertDatabaseHas('attempts', [
            'id'     => $attemptId,
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function candidate_cannot_submit_already_completed_attempt(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];

        $startResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId = $startResponse->json('data.attempt.id');

        // ارسال اول
        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attemptId}/submit");

        // ارسال دوم — باید رد شود
        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attemptId}/submit");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ─── Phase A: Exam Running Status Tests ────────────────────

    #[Test]
    public function candidate_can_start_exam_when_exam_status_is_running(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $data['exam']->update(['status' => 'running']);

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_resume', false)
            ->assertJsonPath('data.attempt.status', 'in_progress');

        $this->assertDatabaseCount('attempts', 1);
        $this->assertDatabaseHas('attempts', [
            'candidate_id' => $candidate->id,
            'exam_id'      => $data['exam']->id,
            'status'       => 'in_progress',
        ]);
    }

    #[Test]
    public function candidate_can_resume_active_attempt_when_exam_status_is_running(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $data['exam']->update(['status' => 'running']);

        // شروع اول
        $firstResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $firstResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_resume', false);

        $attemptId = $firstResponse->json('data.attempt.id');

        // شروع دوم — باید همان Attempt قبلی بدون ایجاد رکورد جدید بازیابی شود
        $resumeResponse = $this->actingAs($candidate, 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $resumeResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_resume', true)
            ->assertJsonPath('data.attempt.id', $attemptId);

        // تعداد رکوردهای Attempt باید ثابت بماند
        $this->assertDatabaseCount('attempts', 1);
    }

    #[Test]
    public function candidate_cannot_start_exam_when_exam_status_is_neither_published_nor_running(): void
    {
        $invalidStatuses = ['draft', 'under_review', 'completed', 'archived'];

        foreach ($invalidStatuses as $status) {
            $data = $this->makeCandidate();
            $candidate = $data['candidate'];
            $data['exam']->update(['status' => $status]);

            $response = $this->actingAs($candidate, 'candidate')
                ->postJson('/api/v1/candidate/attempts/start');

            $response->assertStatus(404)
                ->assertJsonPath('success', false);

            $this->assertDatabaseCount('attempts', 0);
        }
    }
}