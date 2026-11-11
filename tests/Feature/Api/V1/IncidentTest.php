<?php

namespace Tests\Feature\Api\V1;

use App\Models\Candidate;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\Incident;
use App\Models\Lab;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های API برای مدیریت حوادث امتحان.
 * بند 26 SRS — Section 12.3.
 */
class IncidentTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $examAdmin;
    private User $labManager;
    private User $reviewer;
    private Candidate $candidate;
    private Computer $computer;

    /**
     * راه‌اندازی داده‌های مشترک برای همه تست‌ها.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // ساخت نقش‌ها
        $superAdminRole = Role::create(['name' => 'Super Admin',        'is_system_role' => true]);
        $examAdminRole  = Role::create(['name' => 'Exam Administrator', 'is_system_role' => true]);
        $labManagerRole = Role::create(['name' => 'Lab Manager',        'is_system_role' => true]);
        $reviewerRole   = Role::create(['name' => 'Reviewer',           'is_system_role' => true]);

        // ساخت کاربران
        $this->superAdmin = User::create([
            'name'      => 'Super Admin User',
            'username'  => 'superadmin',
            'password'  => bcrypt('password'),
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        $this->examAdmin = User::create([
            'name'      => 'Exam Admin User',
            'username'  => 'examadmin',
            'password'  => bcrypt('password'),
            'role_id'   => $examAdminRole->id,
            'is_active' => true,
        ]);

        $this->labManager = User::create([
            'name'      => 'Lab Manager User',
            'username'  => 'labmanager',
            'password'  => bcrypt('password'),
            'role_id'   => $labManagerRole->id,
            'is_active' => true,
        ]);

        $this->reviewer = User::create([
            'name'      => 'Reviewer User',
            'username'  => 'reviewer',
            'password'  => bcrypt('password'),
            'role_id'   => $reviewerRole->id,
            'is_active' => true,
        ]);

        // ساخت Lab و Computer
        $lab = Lab::create([
            'name'     => 'Lab 1',
            'location' => 'Building A',
        ]);

        $this->computer = Computer::create([
            'lab_id'        => $lab->id,
            'computer_code' => 'LAB1-PC01',
            'ip_address'    => '10.10.1.1',
            'status'        => 'online',
        ]);

        // ساخت Candidate
        $this->candidate = Candidate::create([
            'name'           => 'Test Candidate',
            'candidate_code' => 'KND001',
            'username'       => 'knd001',
            'password'       => bcrypt('password'),
            'status'         => 'present',
        ]);
    }

    /**
     * داده‌های معتبر برای ثبت حادثه.
     */
    private function validIncidentData(array $override = []): array
    {
        return array_merge([
            'candidate_id'        => $this->candidate->id,
            'computer_id'         => $this->computer->id,
            'problem_description' => 'کامپیوتر به‌طور ناگهانی خاموش شد.',
            'action_taken'        => 'متقاضی به کامپیوتر دیگری منتقل شد.',
            'responsible_user_id' => $this->labManager->id,
            'occurred_at'         => now()->subMinutes(10)->toDateTimeString(),
        ], $override);
    }

    /**
     * ساخت یک Incident آماده در دیتابیس.
     */
    private function createIncident(array $override = []): Incident
    {
        return Incident::create(array_merge([
            'candidate_id'        => $this->candidate->id,
            'computer_id'         => $this->computer->id,
            'problem_description' => 'مشکل فنی در جلسه امتحان.',
            'action_taken'        => null,
            'responsible_user_id' => $this->labManager->id,
            'status'              => 'open',
            'occurred_at'         => now()->subMinutes(5),
        ], $override));
    }

    // ===================== INDEX =====================

    #[Test]
    public function lab_manager_can_list_incidents(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $this->createIncident();
        $this->createIncident();

        $response = $this->getJson('/api/v1/admin/incidents');

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure([
                     'success',
                     'data' => ['data'],
                 ]);
    }

    #[Test]
    public function exam_admin_can_list_incidents(): void
    {
        Sanctum::actingAs($this->examAdmin, [], 'admin');

        $this->createIncident();

        $response = $this->getJson('/api/v1/admin/incidents');

        $response->assertOk()
                 ->assertJsonPath('success', true);
    }

    #[Test]
    public function reviewer_cannot_list_incidents(): void
    {
        // Reviewer نقش مجاز برای مشاهده حوادث نیست
        Sanctum::actingAs($this->reviewer, [], 'admin');

        $response = $this->getJson('/api/v1/admin/incidents');

        $response->assertForbidden();
    }

    #[Test]
    public function unauthenticated_user_cannot_list_incidents(): void
    {
        $response = $this->getJson('/api/v1/admin/incidents');

        $response->assertUnauthorized();
    }

    #[Test]
    public function incidents_can_be_filtered_by_status(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $this->createIncident(['status' => 'open']);
        $this->createIncident(['status' => 'resolved', 'resolved_at' => now()]);

        $response = $this->getJson('/api/v1/admin/incidents?status=open');

        $response->assertOk();

        // فقط حوادث open برگردانده می‌شوند
        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertEquals('open', $item['status']);
        }
    }

    #[Test]
    public function incidents_can_be_filtered_by_candidate(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $this->createIncident(['candidate_id' => $this->candidate->id]);

        $response = $this->getJson("/api/v1/admin/incidents?candidate_id={$this->candidate->id}");

        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertEquals($this->candidate->id, $item['candidate_id']);
        }
    }

    // ===================== STORE =====================

    #[Test]
    public function lab_manager_can_create_incident(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $response = $this->postJson('/api/v1/admin/incidents', $this->validIncidentData());

        $response->assertCreated()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.status', 'open')
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'data' => [
                         'id',
                         'candidate_id',
                         'computer_id',
                         'problem_description',
                         'status',
                         'occurred_at',
                     ],
                 ]);

        $this->assertDatabaseHas('incidents', [
            'candidate_id'        => $this->candidate->id,
            'computer_id'         => $this->computer->id,
            'problem_description' => 'کامپیوتر به‌طور ناگهانی خاموش شد.',
            'status'              => 'open',
        ]);
    }

    #[Test]
    public function exam_admin_can_create_incident(): void
    {
        Sanctum::actingAs($this->examAdmin, [], 'admin');

        $response = $this->postJson('/api/v1/admin/incidents', $this->validIncidentData());

        $response->assertCreated()
                 ->assertJsonPath('success', true);
    }

    #[Test]
    public function reviewer_cannot_create_incident(): void
    {
        Sanctum::actingAs($this->reviewer, [], 'admin');

        $response = $this->postJson('/api/v1/admin/incidents', $this->validIncidentData());

        $response->assertForbidden();
    }

    #[Test]
    public function create_incident_fails_without_problem_description(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $data = $this->validIncidentData(['problem_description' => '']);

        $response = $this->postJson('/api/v1/admin/incidents', $data);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['problem_description']);
    }

    #[Test]
    public function create_incident_fails_without_responsible_user(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $data = $this->validIncidentData(['responsible_user_id' => null]);

        $response = $this->postJson('/api/v1/admin/incidents', $data);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['responsible_user_id']);
    }

    #[Test]
    public function create_incident_fails_when_neither_candidate_nor_computer_provided(): void
    {
        // بند 26 SRS: حداقل یکی از candidate یا computer الزامی است
        Sanctum::actingAs($this->labManager, [], 'admin');

        $data = $this->validIncidentData([
            'candidate_id' => null,
            'computer_id'  => null,
        ]);

        $response = $this->postJson('/api/v1/admin/incidents', $data);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['candidate_id']);
    }

    #[Test]
    public function create_incident_succeeds_with_only_computer(): void
    {
        // حادثه بدون متقاضی (مثلاً مشکل سخت‌افزاری عمومی) مجاز است
        Sanctum::actingAs($this->labManager, [], 'admin');

        $data = $this->validIncidentData(['candidate_id' => null]);

        $response = $this->postJson('/api/v1/admin/incidents', $data);

        $response->assertCreated()
                 ->assertJsonPath('success', true);
    }

    #[Test]
    public function create_incident_fails_with_future_occurred_at(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $data = $this->validIncidentData([
            'occurred_at' => now()->addHour()->toDateTimeString(),
        ]);

        $response = $this->postJson('/api/v1/admin/incidents', $data);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['occurred_at']);
    }

    // ===================== SHOW =====================

    #[Test]
    public function lab_manager_can_view_incident(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $incident = $this->createIncident();

        $response = $this->getJson("/api/v1/admin/incidents/{$incident->id}");

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.id', $incident->id)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'id',
                         'status',
                         'problem_description',
                         'occurred_at',
                         'candidate',
                         'computer',
                         'responsible_user',
                     ],
                 ]);
    }

    #[Test]
    public function reviewer_cannot_view_incident(): void
    {
        Sanctum::actingAs($this->reviewer, [], 'admin');

        $incident = $this->createIncident();

        $response = $this->getJson("/api/v1/admin/incidents/{$incident->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function show_returns_404_for_nonexistent_incident(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $response = $this->getJson('/api/v1/admin/incidents/99999');

        $response->assertNotFound();
    }

    // ===================== UPDATE =====================

    #[Test]
    public function lab_manager_can_update_open_incident(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $incident = $this->createIncident(['status' => 'open']);

        $response = $this->putJson("/api/v1/admin/incidents/{$incident->id}", [
            'action_taken' => 'متقاضی به کامپیوتر LAB1-PC02 منتقل شد.',
        ]);

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.action_taken', 'متقاضی به کامپیوتر LAB1-PC02 منتقل شد.');

        $this->assertDatabaseHas('incidents', [
            'id'           => $incident->id,
            'action_taken' => 'متقاضی به کامپیوتر LAB1-PC02 منتقل شد.',
        ]);
    }

    #[Test]
    public function cannot_update_resolved_incident(): void
    {
        // حادثه resolved دیگر قابل ویرایش نیست — Policy این را کنترل می‌کند
        Sanctum::actingAs($this->labManager, [], 'admin');

        $incident = $this->createIncident([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        $response = $this->putJson("/api/v1/admin/incidents/{$incident->id}", [
            'action_taken' => 'تلاش برای ویرایش حادثه حل‌شده.',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function reviewer_cannot_update_incident(): void
    {
        Sanctum::actingAs($this->reviewer, [], 'admin');

        $incident = $this->createIncident(['status' => 'open']);

        $response = $this->putJson("/api/v1/admin/incidents/{$incident->id}", [
            'action_taken' => 'تلاش غیرمجاز.',
        ]);

        $response->assertForbidden();
    }

    // ===================== RESOLVE =====================

    #[Test]
    public function lab_manager_can_resolve_open_incident(): void
    {
        Sanctum::actingAs($this->labManager, [], 'admin');

        $incident = $this->createIncident(['status' => 'open']);

        $response = $this->patchJson("/api/v1/admin/incidents/{$incident->id}/resolve");

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.status', 'resolved');

        $this->assertDatabaseHas('incidents', [
            'id'     => $incident->id,
            'status' => 'resolved',
        ]);

        // resolved_at باید ثبت شده باشد
        $this->assertNotNull(
            Incident::find($incident->id)->resolved_at
        );
    }

    #[Test]
    public function exam_admin_can_resolve_incident(): void
    {
        Sanctum::actingAs($this->examAdmin, [], 'admin');

        $incident = $this->createIncident(['status' => 'open']);

        $response = $this->patchJson("/api/v1/admin/incidents/{$incident->id}/resolve");

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.status', 'resolved');
    }

    #[Test]
    public function cannot_resolve_already_resolved_incident(): void
    {
        // Resolve دوباره یک حادثه حل‌شده باید 422 برگرداند
        Sanctum::actingAs($this->labManager, [], 'admin');

        $incident = $this->createIncident([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        $response = $this->patchJson("/api/v1/admin/incidents/{$incident->id}/resolve");

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    #[Test]
    public function reviewer_cannot_resolve_incident(): void
    {
        Sanctum::actingAs($this->reviewer, [], 'admin');

        $incident = $this->createIncident(['status' => 'open']);

        $response = $this->patchJson("/api/v1/admin/incidents/{$incident->id}/resolve");

        $response->assertForbidden();
    }

    #[Test]
    public function super_admin_can_resolve_incident(): void
    {
        Sanctum::actingAs($this->superAdmin, [], 'admin');

        $incident = $this->createIncident(['status' => 'open']);

        $response = $this->patchJson("/api/v1/admin/incidents/{$incident->id}/resolve");

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.status', 'resolved');
    }
}