<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exam;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های API برای مدیریت آزمون‌ها.
 * هر تست یک سناریوی مشخص را بررسی می‌کند.
 */
class ExamApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $examAdmin;
    private User $reviewer;
    private Subject $subject;

    /**
     * راه‌اندازی داده‌های مشترک برای همه تست‌ها.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'Super Admin',        'is_system_role' => true]);
        $examAdminRole  = Role::create(['name' => 'Exam Administrator', 'is_system_role' => true]);
        $reviewerRole   = Role::create(['name' => 'Reviewer',           'is_system_role' => true]);

        $this->subject = Subject::create([
            'name'      => 'English Language',
            'code'      => 'EN',
            'is_active' => true,
        ]);

        $this->admin = User::create([
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

        $this->reviewer = User::create([
            'name'      => 'Reviewer User',
            'username'  => 'reviewer',
            'password'  => bcrypt('password'),
            'role_id'   => $reviewerRole->id,
            'is_active' => true,
        ]);
    }

    /**
     * داده‌های معتبر برای ساخت آزمون.
     */
    private function validExamData(array $override = []): array
    {
        return array_merge([
            'subject_id'              => $this->subject->id,
            'title'                   => 'English Language Exam',
            'description'             => 'Test description',
            'duration_minutes'        => 60,
            'total_marks'             => 100,
            'pass_marks'              => 60,
            'randomize_questions'     => true,
            'randomize_options'       => true,
            'allow_back_navigation'   => false,
            'show_result_immediately' => false,
        ], $override);
    }

    // ===================== INDEX =====================

    #[Test]
    public function exam_admin_can_list_exams(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->getJson('/api/v1/admin/exams');

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['success', 'data' => ['data']]);
    }

    #[Test]
    public function reviewer_cannot_list_exams(): void
    {
        Sanctum::actingAs($this->reviewer, [], "admin");

        $response = $this->getJson('/api/v1/admin/exams');

        $response->assertForbidden();
    }

    #[Test]
    public function unauthenticated_user_cannot_list_exams(): void
    {
        $response = $this->getJson('/api/v1/admin/exams');

        $response->assertUnauthorized();
    }

    // ===================== STORE =====================

    #[Test]
    public function exam_admin_can_create_exam(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $response = $this->postJson('/api/v1/admin/exams', $this->validExamData());

        $response->assertCreated()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.status', 'draft')
                 ->assertJsonPath('data.title', 'English Language Exam');

        $this->assertDatabaseHas('exams', [
            'title'      => 'English Language Exam',
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]);
    }

    #[Test]
    public function reviewer_cannot_create_exam(): void
    {
        Sanctum::actingAs($this->reviewer, [], "admin");

        $response = $this->postJson('/api/v1/admin/exams', $this->validExamData());

        $response->assertForbidden();
    }

    #[Test]
    public function create_exam_fails_with_invalid_data(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $response = $this->postJson('/api/v1/admin/exams', [
            'title'       => '',
            'pass_marks'  => 200,
            'total_marks' => 100,
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['title', 'subject_id', 'duration_minutes']);
    }

    // ===================== SHOW =====================

    #[Test]
    public function exam_admin_can_view_exam(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->getJson("/api/v1/admin/exams/{$exam->id}");

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.id', $exam->id);
    }

    // ===================== UPDATE =====================

    #[Test]
    public function exam_admin_can_update_draft_exam(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->putJson("/api/v1/admin/exams/{$exam->id}", [
            'title' => 'Updated Title',
        ]);

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.title', 'Updated Title');
    }

    #[Test]
    public function cannot_update_published_exam(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'published',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->putJson("/api/v1/admin/exams/{$exam->id}", [
            'title' => 'Updated Title',
        ]);

        $response->assertForbidden();
    }

    // ===================== CHANGE STATUS =====================

    #[Test]
    public function exam_admin_can_change_status_from_draft_to_under_review(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->patchJson("/api/v1/admin/exams/{$exam->id}/status", [
            'status' => 'under_review',
        ]);

        $response->assertOk()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.status', 'under_review');
    }

    #[Test]
    public function cannot_make_invalid_status_transition(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->patchJson("/api/v1/admin/exams/{$exam->id}/status", [
            'status' => 'published',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonPath('success', false);
    }

    #[Test]
    public function reviewer_cannot_change_status(): void
    {
        Sanctum::actingAs($this->reviewer, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->patchJson("/api/v1/admin/exams/{$exam->id}/status", [
            'status' => 'under_review',
        ]);

        $response->assertForbidden();
    }

    // ===================== DELETE =====================

    #[Test]
    public function exam_admin_can_delete_draft_exam(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->deleteJson("/api/v1/admin/exams/{$exam->id}");

        $response->assertOk()
                 ->assertJsonPath('success', true);

        $this->assertSoftDeleted('exams', ['id' => $exam->id]);
    }

    #[Test]
    public function cannot_delete_published_exam(): void
    {
        Sanctum::actingAs($this->examAdmin, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'published',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->deleteJson("/api/v1/admin/exams/{$exam->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function reviewer_cannot_delete_exam(): void
    {
        Sanctum::actingAs($this->reviewer, [], "admin");

        $exam = Exam::create($this->validExamData([
            'status'     => 'draft',
            'created_by' => $this->examAdmin->id,
        ]));

        $response = $this->deleteJson("/api/v1/admin/exams/{$exam->id}");

        $response->assertForbidden();
    }
}
