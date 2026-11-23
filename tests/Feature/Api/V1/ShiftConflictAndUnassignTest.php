<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\EntryCard;
use App\Models\Exam;
use App\Models\ExamSnapshot;
use App\Models\Lab;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های خودکار مرحله اول: جلوگیری از تداخل شیفت‌ها و قابلیت لغو تخصیص (Unassign).
 */
class ShiftConflictAndUnassignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Lab $lab1;
    private Lab $lab2;
    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['name' => 'Super Admin'],
            ['is_system_role' => true]
        );

        $this->admin = User::factory()->create([
            'role_id' => $superAdminRole->id,
        ]);

        $subject = Subject::create([
            'code' => 'SUBJ_ST_1',
            'name' => 'مضمون تست شیفت',
        ]);

        $this->exam = Exam::create([
            'subject_id'       => $subject->id,
            'title'            => 'آزمون ورودی تست',
            'status'           => 'published',
            'duration_minutes' => 60,
            'total_marks'      => 100,
            'pass_marks'       => 50,
            'created_by'       => $this->admin->id,
        ]);

        $this->lab1 = Lab::create([
            'name'     => 'لابراتوار شماره ۱',
            'location' => 'طبقه اول',
        ]);

        $this->lab2 = Lab::create([
            'name'     => 'لابراتوار شماره ۲',
            'location' => 'طبقه دوم',
        ]);
    }

    #[Test]
    public function test_01_cannot_create_two_overlapping_shifts_in_same_lab(): void
    {
        // شیفت اول: ساعت ۰۸:۰۰ تا ۱۰:۰۰ در لابراتوار ۱
        Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت صبح ۱',
            'start_time' => now()->setTime(8, 0),
            'end_time'   => now()->setTime(10, 0),
            'capacity'   => 30,
        ]);

        // تلاش برای ایجاد شیفت متداخل: ساعت ۰۹:۰۰ تا ۱۱:۰۰ در همان لابراتوار ۱
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/v1/admin/shifts', [
                'exam_id'    => $this->exam->id,
                'lab_id'     => $this->lab1->id,
                'name'       => 'شیفت متداخل',
                'start_time' => now()->setTime(9, 0)->toIso8601String(),
                'end_time'   => now()->setTime(11, 0)->toIso8601String(),
                'capacity'   => 25,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_02_can_create_non_overlapping_shifts_in_same_lab(): void
    {
        // شیفت اول: ساعت ۰۸:۰۰ تا ۱۰:۰۰
        Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت صبح ۱',
            'start_time' => now()->setTime(8, 0),
            'end_time'   => now()->setTime(10, 0),
            'capacity'   => 30,
        ]);

        // شیفت دوم: ساعت ۱۰:۰۰ تا ۱۲:۰۰ (بدون تداخل با قبلی)
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/v1/admin/shifts', [
                'exam_id'    => $this->exam->id,
                'lab_id'     => $this->lab1->id,
                'name'       => 'شیفت صبح ۲',
                'start_time' => now()->setTime(10, 0)->toIso8601String(),
                'end_time'   => now()->setTime(12, 0)->toIso8601String(),
                'capacity'   => 30,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function test_03_can_create_overlapping_shifts_in_different_labs(): void
    {
        // شیفت در لابراتوار ۱: ساعت ۰۸:۰۰ تا ۱۰:۰۰
        Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت لاب ۱',
            'start_time' => now()->setTime(8, 0),
            'end_time'   => now()->setTime(10, 0),
            'capacity'   => 30,
        ]);

        // شیفت هم‌زمان در لابراتوار ۲ (لابراتوار متفاوت کاملاً مجاز است)
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/v1/admin/shifts', [
                'exam_id'    => $this->exam->id,
                'lab_id'     => $this->lab2->id,
                'name'       => 'شیفت لاب ۲',
                'start_time' => now()->setTime(8, 0)->toIso8601String(),
                'end_time'   => now()->setTime(10, 0)->toIso8601String(),
                'capacity'   => 30,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function test_04_can_update_shift_without_conflict_and_self_conflict_ignored(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت اصلی',
            'start_time' => now()->setTime(8, 0),
            'end_time'   => now()->setTime(10, 0),
            'capacity'   => 30,
        ]);

        // تغییر نام یا ظرفیت همان شیفت نباید تداخل با خودش شناسایی شود
        $response = $this->actingAs($this->admin, 'admin')
            ->putJson("/api/v1/admin/shifts/{$shift->id}", [
                'name'     => 'شیفت صبح اصلی ویرایش‌شده',
                'capacity' => 40,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function test_05_cannot_update_shift_into_conflict(): void
    {
        // شیفت ۱: ساعت ۰۸:۰۰ تا ۱۰:۰۰
        Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت صبح',
            'start_time' => now()->setTime(8, 0),
            'end_time'   => now()->setTime(10, 0),
            'capacity'   => 30,
        ]);

        // شیفت ۲: ساعت ۱۲:۰۰ تا ۱۴:۰۰
        $shift2 = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت بعدازظهر',
            'start_time' => now()->setTime(12, 0),
            'end_time'   => now()->setTime(14, 0),
            'capacity'   => 30,
        ]);

        // تلاش برای ویرایش زمان شیفت ۲ به طوری که با شیفت ۱ تداخل پیدا کند (مثلاً ۰۹:۰۰ تا ۱۱:۰۰)
        $response = $this->actingAs($this->admin, 'admin')
            ->putJson("/api/v1/admin/shifts/{$shift2->id}", [
                'start_time' => now()->setTime(9, 0)->toIso8601String(),
                'end_time'   => now()->setTime(11, 0)->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_06_can_unassign_candidate_without_attempt(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت آزمایشی',
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addHour(),
            'capacity'   => 30,
        ]);

        $computer = Computer::create([
            'lab_id'        => $this->lab1->id,
            'computer_code' => 'PC-UNASSIGN-01',
            'ip_address'    => '10.0.1.1',
            'status'        => 'online',
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'KND_UNASSIGN_1',
            'name'           => 'متقاضی بدون آزمون',
            'username'       => 'user_unassign_1',
            'password'       => Hash::make('secret'),
            'status'         => 'present',
            'checked_in_at'  => now(),
        ]);

        CandidateAssignment::create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $this->exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab1->id,
            'computer_id'  => $computer->id,
        ]);

        // ایجاد کارت ورود برای این متقاضی
        $card = EntryCard::create([
            'candidate_id'  => $candidate->id,
            'qr_token'      => bin2hex(random_bytes(32)),
            'qr_expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/v1/admin/candidates/{$candidate->id}/unassign", [
                'reason' => 'انصراف موقت متقاضی',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // تخصیص باید حذف شده باشد
        $this->assertDatabaseMissing('candidate_assignments', [
            'candidate_id' => $candidate->id,
            'exam_id'      => $this->exam->id,
        ]);

        // کارت ورود قبلی باید منقضی شده باشد
        $this->assertTrue($card->fresh()->isExpired());

        // وضعیت چک‌این باید ریست شده باشد
        $this->assertNull($candidate->fresh()->checked_in_at);
    }

    #[Test]
    public function test_07_cannot_unassign_candidate_with_active_attempt(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت فعال',
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addHour(),
            'capacity'   => 30,
        ]);

        $computer = Computer::create([
            'lab_id'        => $this->lab1->id,
            'computer_code' => 'PC-ACTIVE-01',
            'ip_address'    => '10.0.1.2',
            'status'        => 'exam',
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'KND_ACTIVE_1',
            'name'           => 'متقاضی با آزمون فعال',
            'username'       => 'user_active_1',
            'password'       => Hash::make('secret'),
            'status'         => 'in_progress',
        ]);

        $assignment = CandidateAssignment::create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $this->exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab1->id,
            'computer_id'  => $computer->id,
        ]);

        $snapshot = ExamSnapshot::create([
            'exam_id'      => $this->exam->id,
            'version'      => 1,
            'is_immutable' => true,
        ]);

        Attempt::create([
            'candidate_id'            => $candidate->id,
            'exam_id'                 => $this->exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id'        => $snapshot->id,
            'computer_id'             => $computer->id,
            'ip_address'              => '10.0.1.2',
            'started_at'              => now()->subMinutes(5),
            'expires_at'              => now()->addHour(),
            'last_activity_at'        => now(),
            'status'                  => 'in_progress',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/v1/admin/candidates/{$candidate->id}/unassign");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // تخصیص نباید حذف شده باشد
        $this->assertDatabaseHas('candidate_assignments', [
            'id' => $assignment->id,
        ]);
    }

    #[Test]
    public function test_08_cannot_unassign_candidate_with_completed_attempt(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت تمام‌شده',
            'start_time' => now()->subHours(2),
            'end_time'   => now()->subHour(),
            'capacity'   => 30,
        ]);

        $computer = Computer::create([
            'lab_id'        => $this->lab1->id,
            'computer_code' => 'PC-COMP-01',
            'ip_address'    => '10.0.1.3',
            'status'        => 'online',
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'KND_COMP_1',
            'name'           => 'متقاضی با آزمون تمام‌شده',
            'username'       => 'user_comp_1',
            'password'       => Hash::make('secret'),
            'status'         => 'completed',
        ]);

        $assignment = CandidateAssignment::create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $this->exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab1->id,
            'computer_id'  => $computer->id,
        ]);

        $snapshot = ExamSnapshot::create([
            'exam_id'      => $this->exam->id,
            'version'      => 1,
            'is_immutable' => true,
        ]);

        Attempt::create([
            'candidate_id'            => $candidate->id,
            'exam_id'                 => $this->exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id'        => $snapshot->id,
            'computer_id'             => $computer->id,
            'ip_address'              => '10.0.1.3',
            'started_at'              => now()->subHours(2),
            'expires_at'              => now()->subHour(),
            'last_activity_at'        => now()->subHour(),
            'status'                  => 'completed',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/v1/admin/candidates/{$candidate->id}/unassign");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_09_cannot_unassign_another_candidates_assignment(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت تست',
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addHour(),
            'capacity'   => 30,
        ]);

        $candidateA = Candidate::create([
            'candidate_code' => 'KND_A',
            'name'           => 'متقاضی اول',
            'username'       => 'user_a',
            'password'       => Hash::make('secret'),
        ]);

        $candidateB = Candidate::create([
            'candidate_code' => 'KND_B',
            'name'           => 'متقاضی دوم',
            'username'       => 'user_b',
            'password'       => Hash::make('secret'),
        ]);

        $assignmentA = CandidateAssignment::create([
            'candidate_id' => $candidateA->id,
            'exam_id'      => $this->exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab1->id,
        ]);

        // تلاش برای لغو تخصیص A با روت متقاضی B
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/v1/admin/candidates/{$candidateB->id}/unassign", [
                'assignment_id' => $assignmentA->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // تخصیص A نباید آسیب ببیند
        $this->assertDatabaseHas('candidate_assignments', [
            'id' => $assignmentA->id,
        ]);
    }

    #[Test]
    public function test_10_audit_log_is_recorded_for_unassign(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت ممیزی',
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addHour(),
            'capacity'   => 30,
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'KND_AUDIT',
            'name'           => 'متقاضی لاگ ممیزی',
            'username'       => 'user_audit',
            'password'       => Hash::make('secret'),
        ]);

        CandidateAssignment::create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $this->exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab1->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/v1/admin/candidates/{$candidate->id}/unassign", [
                'reason' => 'دلیل ممیزی تست لغو',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'Candidate',
            'target_id'   => $candidate->id,
            'action'      => 'Candidate:unassigned',
        ]);
    }

    #[Test]
    public function test_11_computer_is_properly_released_when_unassigning(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت آزادسازی',
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addHour(),
            'capacity'   => 30,
        ]);

        $computer = Computer::create([
            'lab_id'        => $this->lab1->id,
            'computer_code' => 'PC-RELEASE-01',
            'ip_address'    => '10.0.1.99',
            'status'        => 'exam',
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'KND_REL',
            'name'           => 'متقاضی آزادسازی کامپیوتر',
            'username'       => 'user_rel',
            'password'       => Hash::make('secret'),
        ]);

        CandidateAssignment::create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $this->exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab1->id,
            'computer_id'  => $computer->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/v1/admin/candidates/{$candidate->id}/unassign");

        // وضعیت کامپیوتر باید به online بازگشته باشد
        $this->assertEquals('online', $computer->fresh()->status);
    }

    #[Test]
    public function test_12_entry_card_is_invalidated_after_unassign(): void
    {
        $shift = Shift::create([
            'exam_id'    => $this->exam->id,
            'lab_id'     => $this->lab1->id,
            'name'       => 'شیفت ابطال کارت',
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addHour(),
            'capacity'   => 30,
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'KND_INVALID_CARD',
            'name'           => 'متقاضی ابطال کارت',
            'username'       => 'user_invalid_card',
            'password'       => Hash::make('secret'),
        ]);

        CandidateAssignment::create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $this->exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab1->id,
        ]);

        $card = EntryCard::create([
            'candidate_id'  => $candidate->id,
            'qr_token'      => 'TOKEN_TO_BE_INVALIDATED_123',
            'qr_expires_at' => now()->addDays(20),
        ]);

        // لغو تخصیص
        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/v1/admin/candidates/{$candidate->id}/unassign");

        // استعلام کارت با verify-qr باید منقضی بودن آن را برگرداند
        $verifyRes = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/v1/admin/candidates/verify-qr', [
                'qr_token' => 'TOKEN_TO_BE_INVALIDATED_123',
            ]);

        $verifyRes->assertStatus(422)
            ->assertJsonPath('data.is_expired', true);
    }
}
