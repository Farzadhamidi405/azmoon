<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\ExamSnapshotQuestion;
use App\Models\Lab;
use App\Models\Role;
use App\Models\SessionTransfer;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست Session Transfer API.
 * بند 22 SRS — انتقال جلسه امتحان بین کامپیوترها.
 * پاسخ‌ها و زمان باقیمانده حفظ می‌شود.
 */
class SessionTransferTest extends TestCase
{
    use RefreshDatabase;

    // ─── Setup ────────────────────────────────────────────────

    /**
     * ساخت Attempt فعال با کامپیوتر مشخص.
     */
    private function makeActiveAttempt(): array
    {
        $lab        = Lab::factory()->create();
        $fromComputer = Computer::factory()->create([
            'lab_id' => $lab->id,
            'status' => 'exam',
        ]);
        $toComputer = Computer::factory()->create([
            'lab_id' => $lab->id,
            'status' => 'online',
        ]);

        $exam = Exam::factory()->create([
            'status'           => 'published',
            'duration_minutes' => 60,
        ]);
        $shift = Shift::factory()->create([
            'exam_id' => $exam->id,
            'lab_id'  => $lab->id,
        ]);

        $candidate  = Candidate::factory()->create(['status' => 'present']);
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $lab->id,
            'computer_id'  => $fromComputer->id,
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
            'computer_id'             => $fromComputer->id,
            'status'                  => 'in_progress',
            'expires_at'              => now()->addMinutes(60),
        ]);

        return compact('attempt', 'fromComputer', 'toComputer', 'lab');
    }

    /**
     * ساخت Admin با نقش مشخص.
     */
    private function makeAdmin(string $roleName = 'Super Admin'): User
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['is_system_role' => true],
        );
        return User::factory()->create(['role_id' => $role->id]);
    }

    // ─── Index ────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_list_session_transfers(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        SessionTransfer::factory()->count(3)->create([
            'attempt_id'       => $attempt->id,
            'from_computer_id' => $data['fromComputer']->id,
            'to_computer_id'   => $data['toComputer']->id,
            'transferred_by'   => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers");

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function lab_manager_can_list_session_transfers(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Lab Manager');

        SessionTransfer::factory()->count(2)->create([
            'attempt_id'       => $attempt->id,
            'from_computer_id' => $data['fromComputer']->id,
            'to_computer_id'   => $data['toComputer']->id,
            'transferred_by'   => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers");

        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    #[Test]
    public function reviewer_cannot_list_session_transfers(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Reviewer');

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers");

        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_list_session_transfers(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $response = $this->getJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers");

        $response->assertStatus(401);
    }

    // ─── Store ────────────────────────────────────────────────

    #[Test]
    public function lab_manager_can_transfer_session(): void
    {
        $data       = $this->makeActiveAttempt();
        $attempt    = $data['attempt'];
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Lab Manager');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'خرابی کامپیوتر مبدأ.',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attempt_id', $attempt->id)
            ->assertJsonPath('data.to_computer_id', $toComputer->id);

        $this->assertDatabaseHas('session_transfers', [
            'attempt_id'     => $attempt->id,
            'to_computer_id' => $toComputer->id,
            'transferred_by' => $admin->id,
        ]);
    }

    #[Test]
    public function super_admin_can_transfer_session(): void
    {
        $data       = $this->makeActiveAttempt();
        $attempt    = $data['attempt'];
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Super Admin');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'انتقال دستی توسط Super Admin.',
            ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
    }

    #[Test]
    public function reviewer_cannot_transfer_session(): void
    {
        $data       = $this->makeActiveAttempt();
        $attempt    = $data['attempt'];
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Reviewer');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'تلاش غیرمجاز.',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function transfer_updates_attempt_computer(): void
    {
        $data       = $this->makeActiveAttempt();
        $attempt    = $data['attempt'];
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Lab Manager');

        $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'تغییر کامپیوتر به دلیل خرابی.',
            ]);

        // بررسی به‌روزرسانی computer_id در Attempt
        $this->assertDatabaseHas('attempts', [
            'id'          => $attempt->id,
            'computer_id' => $toComputer->id,
        ]);
    }

    #[Test]
    public function transfer_is_recorded_in_audit_log(): void
    {
        $data       = $this->makeActiveAttempt();
        $attempt    = $data['attempt'];
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Lab Manager');

        $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'انتقال برای تست Audit Log.',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id'     => $admin->id,
            'action'      => 'SessionTransfer',
            'module'      => 'attempts',
            'target_type' => 'Attempt',
            'target_id'   => $attempt->id,
        ]);
    }

    #[Test]
    public function cannot_transfer_to_same_computer(): void
    {
        $data        = $this->makeActiveAttempt();
        $attempt     = $data['attempt'];
        $fromComputer = $data['fromComputer'];
        $admin       = $this->makeAdmin('Lab Manager');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $fromComputer->id,
                'reason'         => 'تلاش برای انتقال به همان کامپیوتر.',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function cannot_transfer_to_offline_computer(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Lab Manager');

        $offlineComputer = Computer::factory()->create([
            'lab_id' => $data['lab']->id,
            'status' => 'offline',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $offlineComputer->id,
                'reason'         => 'تلاش برای انتقال به کامپیوتر آفلاین.',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function cannot_transfer_completed_attempt(): void
    {
        $data       = $this->makeActiveAttempt();
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Lab Manager');

        $attempt = $data['attempt'];
        $attempt->update(['status' => 'completed']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'تلاش برای انتقال Attempt تکمیل‌شده.',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function disconnected_attempt_can_be_transferred(): void
    {
        $data       = $this->makeActiveAttempt();
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Lab Manager');

        $attempt = $data['attempt'];
        $attempt->update(['status' => 'disconnected']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'انتقال Attempt قطع‌شده به کامپیوتر جدید.',
            ]);

        $response->assertStatus(201);
    }

    #[Test]
    public function reason_is_required(): void
    {
        $data       = $this->makeActiveAttempt();
        $attempt    = $data['attempt'];
        $toComputer = $data['toComputer'];
        $admin      = $this->makeAdmin('Lab Manager');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => $toComputer->id,
                // reason عمداً حذف شده
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    #[Test]
    public function to_computer_id_is_required(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Lab Manager');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'reason' => 'تلاش بدون to_computer_id.',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['to_computer_id']);
    }

    #[Test]
    public function to_computer_must_exist(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Lab Manager');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers", [
                'to_computer_id' => 99999,
                'reason'         => 'تلاش با کامپیوتر ناموجود.',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['to_computer_id']);
    }

    #[Test]
    public function guest_cannot_transfer_session(): void
    {
        $data       = $this->makeActiveAttempt();
        $attempt    = $data['attempt'];
        $toComputer = $data['toComputer'];

        $response = $this->postJson(
            "/api/v1/admin/attempts/{$attempt->id}/session-transfers",
            [
                'to_computer_id' => $toComputer->id,
                'reason'         => 'تلاش بدون احراز هویت.',
            ]
        );

        $response->assertStatus(401);
    }

    // ─── Show ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_show_session_transfer(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $transfer = SessionTransfer::factory()->create([
            'attempt_id'       => $attempt->id,
            'from_computer_id' => $data['fromComputer']->id,
            'to_computer_id'   => $data['toComputer']->id,
            'transferred_by'   => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers/{$transfer->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $transfer->id);
    }

    #[Test]
    public function show_returns_404_for_transfer_belonging_to_different_attempt(): void
    {
        $data1   = $this->makeActiveAttempt();
        $data2   = $this->makeActiveAttempt();
        $attempt1 = $data1['attempt'];
        $attempt2 = $data2['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $transfer = SessionTransfer::factory()->create([
            'attempt_id'       => $attempt2->id,
            'from_computer_id' => $data2['fromComputer']->id,
            'to_computer_id'   => $data2['toComputer']->id,
            'transferred_by'   => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt1->id}/session-transfers/{$transfer->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function reviewer_cannot_show_session_transfer(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');
        $other   = $this->makeAdmin('Reviewer');

        $transfer = SessionTransfer::factory()->create([
            'attempt_id'       => $attempt->id,
            'from_computer_id' => $data['fromComputer']->id,
            'to_computer_id'   => $data['toComputer']->id,
            'transferred_by'   => $admin->id,
        ]);

        $response = $this->actingAs($other, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/session-transfers/{$transfer->id}");

        $response->assertStatus(403);
    }
}