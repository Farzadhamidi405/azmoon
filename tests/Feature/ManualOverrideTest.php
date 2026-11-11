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
use App\Models\ManualOverride;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;


/**
 * تست Manual Override API.
 * بند 27 SRS — عملیات دستی ادمین روی Attempt.
 * Override ها immutable هستند — update/delete مجاز نیست.
 */
class ManualOverrideTest extends TestCase
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

        $candidate  = Candidate::factory()->create(['status' => 'present']);
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

    /**
 * ساخت Admin با نقش مشخص.
 */
    private function makeAdmin(string $roleName = 'Super Admin'): User
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['is_system_role' => true]
        );
        return User::factory()->create(['role_id' => $role->id]);
    }
    // ─── Index ────────────────────────────────────────────────

    #[Test]
    public function admin_can_list_overrides_for_attempt(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        // ساخت چند Override برای این Attempt
        ManualOverride::factory()->count(3)->create([
            'attempt_id'   => $attempt->id,
            'performed_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/overrides");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function exam_administrator_can_list_overrides(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Exam Administrator');

        ManualOverride::factory()->count(2)->create([
            'attempt_id'   => $attempt->id,
            'performed_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/overrides");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function unauthorized_role_cannot_list_overrides(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Lab Manager');

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/overrides");

        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_list_overrides(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $response = $this->getJson("/api/v1/admin/attempts/{$attempt->id}/overrides");

        $response->assertStatus(401);
    }

    // ─── Store ────────────────────────────────────────────────

    #[Test]
    public function admin_can_store_manual_override(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action'    => 'extend_time',
                'reason'    => 'متقاضی به خاطر قطع برق به وقت اضافه نیاز دارد.',
                'old_value' => ['remaining_seconds' => 600],
                'new_value' => ['remaining_seconds' => 1200],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action', 'extend_time')
            ->assertJsonPath('data.attempt_id', $attempt->id);

        $this->assertDatabaseHas('manual_overrides', [
            'attempt_id'   => $attempt->id,
            'action'       => 'extend_time',
            'performed_by' => $admin->id,
        ]);
    }

    #[Test]
    public function override_is_recorded_in_audit_log(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'force_submit',
                'reason' => 'زمان امتحان تمام شده و سیستم به‌صورت دستی Submit می‌کند.',
            ]);

        // بررسی ثبت در audit_logs — بند 27 و 33 SRS
        $this->assertDatabaseHas('audit_logs', [
            'user_id'     => $admin->id,
            'action'      => 'ManualOverride:force_submit',
            'module'      => 'attempts',
            'target_type' => 'Attempt',
            'target_id'   => $attempt->id,
        ]);
    }

    #[Test]
    public function exam_administrator_can_store_override(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Exam Administrator');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'void_attempt',
                'reason' => 'متقاضی تقلب کرده و Attempt باطل می‌شود.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function lab_manager_cannot_store_override(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Lab Manager');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'extend_time',
                'reason' => 'تلاش غیرمجاز برای Override.',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function reason_is_required_for_override(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'extend_time',
                // reason عمداً حذف شده
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    #[Test]
    public function reason_must_be_at_least_10_characters(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'extend_time',
                'reason' => 'کوتاه',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    #[Test]
    public function invalid_action_is_rejected(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'invalid_action',
                'reason' => 'این action معتبر نیست و باید رد شود.',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    #[Test]
    public function action_is_required(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'reason' => 'این تست بدون action ارسال شده است.',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    #[Test]
    public function all_valid_actions_are_accepted(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $validActions = [
            'extend_time',
            'reset_session',
            'transfer_session',
            'force_submit',
            'reopen_attempt',
            'change_computer',
            'mark_absent',
            'void_attempt',
        ];

        foreach ($validActions as $action) {
            $response = $this->actingAs($admin, 'admin')
                ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                    'action' => $action,
                    'reason' => "دلیل تست برای action: {$action}",
                ]);

            $response->assertStatus(201,
                "Action '{$action}' باید پذیرفته شود اما رد شد."
            );
        }
    }

    #[Test]
    public function old_value_and_new_value_are_optional(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'mark_absent',
                'reason' => 'متقاضی در جلسه حاضر نشده است.',
                // old_value و new_value ارسال نشده
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function performed_by_is_set_to_authenticated_user(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'extend_time',
                'reason' => 'تست تنظیم خودکار performed_by.',
            ]);

        $this->assertDatabaseHas('manual_overrides', [
            'attempt_id'   => $attempt->id,
            'performed_by' => $admin->id,
        ]);
    }

    #[Test]
    public function multiple_overrides_can_be_stored_for_same_attempt(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');

        // ثبت سه Override روی یک Attempt — immutable audit trail
        $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'extend_time',
                'reason' => 'اول: تمدید وقت به خاطر مشکل فنی.',
            ]);

        $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'change_computer',
                'reason' => 'دوم: تغییر کامپیوتر به خاطر خرابی.',
            ]);

        $this->actingAs($admin, 'admin')
            ->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
                'action' => 'force_submit',
                'reason' => 'سوم: ارسال اجباری پس از پایان وقت.',
            ]);

        $this->assertEquals(3, ManualOverride::where('attempt_id', $attempt->id)->count());
    }

    // ─── Show ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_show_override(): void
    {
        $data     = $this->makeActiveAttempt();
        $attempt  = $data['attempt'];
        $admin    = $this->makeAdmin('Super Admin');

        $override = ManualOverride::factory()->create([
            'attempt_id'   => $attempt->id,
            'performed_by' => $admin->id,
            'action'       => 'extend_time',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/overrides/{$override->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $override->id)
            ->assertJsonPath('data.action', 'extend_time');
    }

    #[Test]
    public function show_returns_404_for_override_belonging_to_different_attempt(): void
    {
        $data1    = $this->makeActiveAttempt();
        $data2    = $this->makeActiveAttempt();
        $attempt1 = $data1['attempt'];
        $attempt2 = $data2['attempt'];
        $admin    = $this->makeAdmin('Super Admin');

        // Override متعلق به attempt2 است
        $override = ManualOverride::factory()->create([
            'attempt_id'   => $attempt2->id,
            'performed_by' => $admin->id,
        ]);

        // تلاش برای دیدن Override از مسیر attempt1
        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt1->id}/overrides/{$override->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function unauthorized_role_cannot_show_override(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $admin   = $this->makeAdmin('Super Admin');
        $other   = $this->makeAdmin('Lab Manager');

        $override = ManualOverride::factory()->create([
            'attempt_id'   => $attempt->id,
            'performed_by' => $admin->id,
        ]);

        $response = $this->actingAs($other, 'admin')
            ->getJson("/api/v1/admin/attempts/{$attempt->id}/overrides/{$override->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_store_override(): void
    {
        $data    = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $response = $this->postJson("/api/v1/admin/attempts/{$attempt->id}/overrides", [
            'action' => 'extend_time',
            'reason' => 'تلاش بدون احراز هویت.',
        ]);

        $response->assertStatus(401);
    }
}