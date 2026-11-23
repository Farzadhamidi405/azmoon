<?php

namespace Tests\Feature\Api\V1;

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
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های خودکار نوزده‌گانه فاز ۵ — موتور شروع آزمون و ایجاد Attempt.
 */
class ExamStartEngineTest extends TestCase
{
    use RefreshDatabase;

    private function createReadyCandidateBundle(array $overrides = []): array
    {
        $admin = User::first() ?? User::factory()->create();

        $subject = Subject::firstOrCreate(
            ['code' => 'SUBJ_P5'],
            ['name' => 'مضمون تست فاز ۵']
        );

        $exam = Exam::create([
            'subject_id'       => $subject->id,
            'title'            => $overrides['exam_title'] ?? 'آزمون ورودی معتبر',
            'status'           => $overrides['exam_status'] ?? 'published',
            'duration_minutes' => 60,
            'total_marks'      => 100,
            'pass_marks'       => 50,
            'created_by'       => $admin->id,
        ]);

        $lab = Lab::create([
            'name'     => 'لابراتوار مرکزی تست',
            'location' => 'طبقه دوم',
        ]);

        $computer = Computer::create([
            'lab_id'        => $lab->id,
            'computer_code' => $overrides['computer_code'] ?? ('PC-TEST-' . uniqid()),
            'ip_address'    => '10.0.5.1',
            'status'        => $overrides['computer_status'] ?? 'online',
        ]);

        $shift = Shift::create([
            'exam_id'    => $exam->id,
            'lab_id'     => $lab->id,
            'name'       => 'شیفت آزمایشی',
            'start_time' => $overrides['shift_start'] ?? now()->subMinutes(10),
            'end_time'   => $overrides['shift_end'] ?? now()->addHours(2),
            'capacity'   => 30,
        ]);

        $candidate = Candidate::create([
            'candidate_code'        => $overrides['candidate_code'] ?? ('KND_' . uniqid()),
            'name'                  => 'داوطلب آماده شروع',
            'username'              => $overrides['username'] ?? ('cand_' . uniqid()),
            'password'              => Hash::make('secret123'),
            'status'                => $overrides['candidate_status'] ?? 'present',
            'checked_in_at'         => array_key_exists('checked_in_at', $overrides) ? $overrides['checked_in_at'] : now()->subMinutes(5),
            'failed_login_attempts' => $overrides['failed_attempts'] ?? 0,
            'locked_until'          => $overrides['locked_until'] ?? null,
            'credential_expires_at' => $overrides['credential_expires_at'] ?? null,
        ]);

        $assignment = null;
        if (!($overrides['skip_assignment'] ?? false)) {
            $assignment = CandidateAssignment::create([
                'candidate_id' => $candidate->id,
                'exam_id'      => $exam->id,
                'shift_id'     => $shift->id,
                'lab_id'       => $lab->id,
                'computer_id'  => $overrides['skip_computer'] ?? false ? null : $computer->id,
            ]);
        }

        $snapshot = ExamSnapshot::create([
            'exam_id'      => $exam->id,
            'version'      => 1,
            'is_immutable' => true,
        ]);

        return compact('candidate', 'exam', 'shift', 'lab', 'computer', 'assignment', 'snapshot');
    }

    #[Test]
    public function test_01_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/candidate/attempts/start');
        $response->assertStatus(401);
    }

    #[Test]
    public function test_02_staff_admin_cannot_use_candidate_start_route(): void
    {
        $admin = User::first() ?? User::factory()->create();
        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(401);
    }

    #[Test]
    public function test_03_locked_candidate_cannot_start_exam(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'locked_until' => now()->addDay(),
            'failed_attempts' => 5,
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_04_disqualified_candidate_cannot_start_exam(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'candidate_status' => 'disqualified',
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_05_candidate_without_assignment_cannot_start(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'skip_assignment' => true,
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_06_candidate_cannot_hijack_another_candidates_assignment(): void
    {
        $bundle1 = $this->createReadyCandidateBundle(['candidate_code' => 'KND_A', 'username' => 'cand_a']);
        $bundle2 = $this->createReadyCandidateBundle(['candidate_code' => 'KND_B', 'username' => 'cand_b', 'skip_assignment' => true]);

        // حتی اگر candidate2 شناسه candidate1 را ارسال کند، سرور منحصراً از Auth Guard استفاده می‌کند
        $response = $this->actingAs($bundle2['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start', ['candidate_id' => $bundle1['candidate']->id]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_07_draft_exam_cannot_be_started(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'exam_status' => 'draft',
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_08_archived_or_cancelled_exam_cannot_be_started(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'exam_status' => 'archived',
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_09_candidate_without_verified_identity_cannot_start(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'candidate_status' => 'absent',
            'checked_in_at'    => null,
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_10_candidate_without_checkin_cannot_start(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'checked_in_at'    => null,
            'candidate_status' => 'late',
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_11_candidate_without_assigned_computer_cannot_start(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'skip_computer' => true,
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_12_candidate_cannot_start_before_shift_begins(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'shift_start' => now()->addMinutes(30),
            'shift_end'   => now()->addHours(2),
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_13_candidate_cannot_start_after_shift_ends(): void
    {
        $bundle = $this->createReadyCandidateBundle([
            'shift_start' => now()->subHours(3),
            'shift_end'   => now()->subMinutes(15),
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_14_eligible_candidate_successfully_creates_attempt(): void
    {
        $bundle = $this->createReadyCandidateBundle();

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attempt.status', 'in_progress');

        $this->assertDatabaseHas('attempts', [
            'candidate_id' => $bundle['candidate']->id,
            'exam_id'      => $bundle['exam']->id,
            'status'       => 'in_progress',
        ]);
    }

    #[Test]
    public function test_15_duplicate_start_request_does_not_create_duplicate_attempt(): void
    {
        $bundle = $this->createReadyCandidateBundle();

        // درخواست اول
        $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        // درخواست تکراری دوم
        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(200)
            ->assertJsonPath('data.attempt.is_resume', true);

        // تنها ۱ رکورد Attempt در دیتابیس باید وجود داشته باشد
        $this->assertDatabaseCount('attempts', 1);
    }

    #[Test]
    public function test_16_existing_active_attempt_is_safely_resumed(): void
    {
        $bundle = $this->createReadyCandidateBundle();

        $startRes = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId = $startRes->json('data.attempt.id');

        // تلاش دوم
        $resumeRes = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $this->assertEquals($attemptId, $resumeRes->json('data.attempt.id'));
        $this->assertTrue($resumeRes->json('data.attempt.is_resume'));
    }

    #[Test]
    public function test_17_completed_attempt_cannot_be_restarted(): void
    {
        $bundle = $this->createReadyCandidateBundle();

        // ایجاد رکورد تلاش تکمیل‌شده
        Attempt::create([
            'candidate_id'            => $bundle['candidate']->id,
            'exam_id'                 => $bundle['exam']->id,
            'candidate_assignment_id' => $bundle['assignment']->id,
            'exam_snapshot_id'        => $bundle['snapshot']->id,
            'computer_id'             => $bundle['computer']->id,
            'ip_address'              => '127.0.0.1',
            'started_at'              => now()->subHour(),
            'expires_at'              => now()->addHour(),
            'last_activity_at'        => now(),
            'status'                  => 'completed',
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_18_concurrent_requests_are_serialized_under_lock(): void
    {
        $bundle = $this->createReadyCandidateBundle();

        // شبیه‌سازی دو فراخوانی متوالی سریع
        $res1 = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $res2 = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $res1->assertStatus(200);
        $res2->assertStatus(200);

        // دقیقاً یک تلاش ایجاد شده باشد
        $this->assertEquals(1, Attempt::where('candidate_id', $bundle['candidate']->id)->count());
    }

    #[Test]
    public function test_19_candidate_cannot_access_another_candidates_attempt_state(): void
    {
        $bundle1 = $this->createReadyCandidateBundle(['candidate_code' => 'KND_X', 'username' => 'cand_x']);
        $bundle2 = $this->createReadyCandidateBundle(['candidate_code' => 'KND_Y', 'username' => 'cand_y']);

        $res1 = $this->actingAs($bundle1['candidate'], 'candidate')
            ->postJson('/api/v1/candidate/attempts/start');

        $attemptId1 = $res1->json('data.attempt.id');

        // متقاضی ۲ سعی می‌کند وضعیت تلاش متقاضی ۱ را دریافت کند
        $response = $this->actingAs($bundle2['candidate'], 'candidate')
            ->getJson("/api/v1/candidate/attempts/{$attemptId1}/state");

        $response->assertStatus(403);
    }
}
