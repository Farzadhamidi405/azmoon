<?php

namespace Tests\Feature;

use App\Models\AntiCheatEvent;
use App\Models\Attempt;
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
 * تست Anti-Cheat Engine.
 * بند 20 و 21 SRS.
 */
class AntiCheatTest extends TestCase
{
    use RefreshDatabase;

    // ─── Setup ────────────────────────────────────────────────

    /**
     * ساخت Attempt فعال برای تست.
     */
    private function makeActiveAttempt(): array
    {
        $lab      = Lab::factory()->create();
        $computer = Computer::factory()->create(['lab_id' => $lab->id, 'status' => 'online']);
        $exam     = Exam::factory()->create(['status' => 'published', 'duration_minutes' => 60]);
        $shift    = Shift::factory()->create(['exam_id' => $exam->id, 'lab_id' => $lab->id]);
        $candidate = Candidate::factory()->create(['status' => 'present']);
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $lab->id,
            'computer_id'  => $computer->id,
        ]);
        $snapshot = ExamSnapshot::factory()->create(['exam_id' => $exam->id]);
        $section  = ExamSection::factory()->create(['exam_id' => $exam->id]);
        ExamSnapshotQuestion::factory()->count(3)->create([
            'exam_snapshot_id' => $snapshot->id,
            'exam_section_id'  => $section->id,
        ]);

        $attempt = Attempt::factory()->create([
            'candidate_id'            => $candidate->id,
            'exam_id'                 => $exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id'        => $snapshot->id,
            'computer_id'             => $computer->id,
            'status'                  => 'in_progress',
            'expires_at'              => now()->addMinutes(60),
        ]);

        return compact('candidate', 'attempt', 'exam');
    }

    // ─── Record Event ─────────────────────────────────────────

    #[Test]
    public function candidate_can_record_anti_cheat_event(): void
    {
        $data      = $this->makeActiveAttempt();
        $candidate = $data['candidate'];
        $attempt   = $data['attempt'];

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attempt->id}/anti-cheat/event", [
                'event_type'  => 'tab_switch',
                'meta'        => ['from' => 'exam', 'to' => 'other'],
                'occurred_at' => now()->toISOString(),
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseHas('anti_cheat_events', [
            'attempt_id' => $attempt->id,
            'event_type' => 'tab_switch',
        ]);
    }

    #[Test]
    public function recording_event_recalculates_risk_score(): void
    {
        $data      = $this->makeActiveAttempt();
        $candidate = $data['candidate'];
        $attempt   = $data['attempt'];

        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attempt->id}/anti-cheat/event", [
                'event_type' => 'tab_switch',
            ]);

        $this->assertDatabaseHas('attempt_risk_scores', [
            'attempt_id' => $attempt->id,
        ]);
    }

    #[Test]
    public function candidate_cannot_record_event_for_another_candidates_attempt(): void
    {
        $data1     = $this->makeActiveAttempt();
        $data2     = $this->makeActiveAttempt();
        $attempt   = $data1['attempt'];
        $candidate = $data2['candidate'];

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attempt->id}/anti-cheat/event", [
                'event_type' => 'tab_switch',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function invalid_event_type_is_rejected(): void
    {
        $data      = $this->makeActiveAttempt();
        $candidate = $data['candidate'];
        $attempt   = $data['attempt'];

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attempt->id}/anti-cheat/event", [
                'event_type' => 'invalid_event_type',
            ]);

        $response->assertStatus(422);
    }

    // ─── Risk Score ───────────────────────────────────────────

    #[Test]
    public function admin_can_get_risk_score(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = User::factory()->create();

        // ثبت چند رویداد
        AntiCheatEvent::factory()->count(3)->create([
            'attempt_id' => $attempt->id,
            'event_type' => 'tab_switch',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/risk-score");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'attempt_id',
                    'risk_score' => ['score', 'level', 'computed_at'],
                    'event_summary',
                    'events',
                ],
            ]);
    }

    #[Test]
    public function risk_score_increases_with_more_events(): void
    {
        $data      = $this->makeActiveAttempt();
        $candidate = $data['candidate'];
        $attempt   = $data['attempt'];

        // ثبت ۵ رویداد
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($candidate, 'candidate')
                ->postJson("/api/v1/candidate/attempts/{$attempt->id}/anti-cheat/event", [
                    'event_type' => 'tab_switch',
                ])
                ->assertOk();
        }

        $lastScore = $attempt->riskScores()->latest('computed_at')->latest('id')->first();

        // وزن tab_switch = 3، پس 5 × 3 = 15
        $this->assertEquals(15, $lastScore->score);
        $this->assertEquals('low', $lastScore->level);
    }
}
