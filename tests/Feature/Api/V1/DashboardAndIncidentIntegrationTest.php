<?php

namespace Tests\Feature\Api\V1;

use App\Models\Candidate;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\Incident;
use App\Models\Lab;
use App\Models\Role;
use App\Models\User;
use App\Models\Attempt;
use App\Models\CandidateAssignment;
use App\Models\ExamSnapshot;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های یکپارچگی داشبورد، جلسات فعال و مدیریت حوادث.
 * فاز 4.1 — سامانه آزمون.
 */
class DashboardAndIncidentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Lab $lab;
    private Computer $computer;
    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name'           => 'Super Admin',
            'is_system_role' => true,
        ]);

        $this->admin = User::create([
            'name'      => 'مدیر آزمون',
            'username'  => 'exam_admin',
            'password'  => bcrypt('password123'),
            'role_id'   => $role->id,
            'is_active' => true,
        ]);

        $this->lab = Lab::create([
            'name'     => 'لابراتوار کامپیوتر مرکزی',
            'location' => 'دانشکده کامپیوتر ساینس',
        ]);

        $this->computer = Computer::create([
            'lab_id'        => $this->lab->id,
            'computer_code' => 'LAB-PC01',
            'ip_address'    => '192.168.1.101',
            'status'        => 'online',
        ]);

        $this->candidate = Candidate::create([
            'name'           => 'محمد احمدی',
            'candidate_code' => 'KND-1001',
            'username'       => 'knd1001',
            'password'       => bcrypt('password123'),
            'status'         => 'in_progress',
        ]);
    }

    #[Test]
    public function dashboard_stats_returns_accurate_counts_and_recent_activity(): void
    {
        Sanctum::actingAs($this->admin, [], 'admin');

        Exam::factory()->create(['status' => 'published']);
        Exam::factory()->create(['status' => 'running']);

        $response = $this->getJson('/api/v1/admin/dashboard-stats');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'published_exams',
                    'running_exams',
                    'candidates_count',
                    'in_progress_count',
                    'recent_activity',
                ],
            ]);

        $this->assertEquals(1, $response->json('data.published_exams'));
        $this->assertEquals(1, $response->json('data.running_exams'));
        $this->assertEquals(1, $response->json('data.candidates_count'));
        $this->assertEquals(1, $response->json('data.in_progress_count'));
    }

    #[Test]
    public function active_sessions_api_returns_formatted_sessions_for_dashboard(): void
    {
        Sanctum::actingAs($this->admin, [], 'admin');

        $exam = Exam::factory()->create(['status' => 'published']);
        $shift = Shift::factory()->create(['exam_id' => $exam->id, 'lab_id' => $this->lab->id]);
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $this->candidate->id,
            'exam_id'      => $exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $this->lab->id,
            'computer_id'  => $this->computer->id,
        ]);
        $snapshot = ExamSnapshot::factory()->create(['exam_id' => $exam->id]);

        Attempt::factory()->create([
            'candidate_id'            => $this->candidate->id,
            'exam_id'                 => $exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id'        => $snapshot->id,
            'computer_id'             => $this->computer->id,
            'status'                  => 'in_progress',
            'expires_at'              => now()->addMinutes(45),
        ]);

        $response = $this->getJson('/api/v1/admin/sessions?per_page=5');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $sessions = $response->json('data.data');
        $this->assertNotEmpty($sessions);
        $this->assertEquals('in_progress', $sessions[0]['status']);
        $this->assertEquals('محمد احمدی', $sessions[0]['candidate']['name']);
        $this->assertEquals('LAB-PC01', $sessions[0]['computer']['computer_code']);
    }

    #[Test]
    public function incidents_can_be_filtered_by_status_and_searched(): void
    {
        Sanctum::actingAs($this->admin, [], 'admin');

        Incident::create([
            'candidate_id'        => $this->candidate->id,
            'computer_id'         => $this->computer->id,
            'problem_description' => 'قطع موقت کابل شبکه در زمان آزمون',
            'action_taken'        => 'بررسی و اتصال مجدد کابل',
            'responsible_user_id' => $this->admin->id,
            'status'              => 'open',
            'occurred_at'         => now()->subMinutes(15),
        ]);

        Incident::create([
            'candidate_id'        => null,
            'computer_id'         => $this->computer->id,
            'problem_description' => 'خاموشی ناگهانی پاور سیستم پس از پایان شیفت',
            'action_taken'        => 'تعویض منبع تغذیه',
            'responsible_user_id' => $this->admin->id,
            'status'              => 'resolved',
            'occurred_at'         => now()->subHours(2),
            'resolved_at'         => now()->subHour(),
        ]);

        // تست فیلتر وضعیت open
        $resOpen = $this->getJson('/api/v1/admin/incidents?status=open');
        $resOpen->assertOk();
        $this->assertCount(1, $resOpen->json('data.data'));
        $this->assertEquals('open', $resOpen->json('data.data.0.status'));

        // تست فیلتر وضعیت resolved
        $resResolved = $this->getJson('/api/v1/admin/incidents?status=resolved');
        $resResolved->assertOk();
        $this->assertCount(1, $resResolved->json('data.data'));
        $this->assertEquals('resolved', $resResolved->json('data.data.0.status'));

        // تست جستجو در متن شرح مشکل
        $resSearch = $this->getJson('/api/v1/admin/incidents?search=شبکه');
        $resSearch->assertOk();
        $this->assertCount(1, $resSearch->json('data.data'));
        $this->assertStringContainsString('شبکه', $resSearch->json('data.data.0.problem_description'));
    }

    #[Test]
    public function incident_can_be_created_updated_and_resolved(): void
    {
        Sanctum::actingAs($this->admin, [], 'admin');

        // ۱. ایجاد حادثه جدید
        $createRes = $this->postJson('/api/v1/admin/incidents', [
            'computer_id'         => $this->computer->id,
            'problem_description' => 'افت سرعت فاحش سیستم و هنگ برنامه آزمون',
            'action_taken'        => null,
            'responsible_user_id' => $this->admin->id,
            'occurred_at'         => now()->toDateTimeString(),
        ]);

        $createRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'open');

        $incidentId = $createRes->json('data.id');

        // ۲. ثبت اقدام انجام‌شده
        $updateRes = $this->putJson("/api/v1/admin/incidents/{$incidentId}", [
            'action_taken' => 'ریستارت سیستم و آزادسازی حافظه رم',
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action_taken', 'ریستارت سیستم و آزادسازی حافظه رم');

        // ۳. علامت‌گذاری به عنوان حل‌شده با متد patch
        $resolveRes = $this->patchJson("/api/v1/admin/incidents/{$incidentId}/resolve");

        $resolveRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'resolved');

        $this->assertDatabaseHas('incidents', [
            'id'     => $incidentId,
            'status' => 'resolved',
        ]);
    }
}
