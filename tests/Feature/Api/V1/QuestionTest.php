<?php

namespace Tests\Feature\Api\V1;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subject;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QuestionTest — تست کامل API بانک سوالات.
 *
 * پوشش:
 *  - CRUD (ایجاد، مشاهده، ویرایش، حذف)
 *  - Approval Workflow (تغییر وضعیت)
 *  - Versioning (تاریخچه نسخه‌ها)
 *  - Authorization (کنترل دسترسی بر اساس نقش)
 *  - Validation (اعتبارسنجی ورودی)
 */
class QuestionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $examAdmin;
    private User $questionManager;
    private User $reviewer;
    private User $labManager;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole    = Role::create(['name' => 'Super Admin',        'is_system_role' => true]);
        $examAdminRole     = Role::create(['name' => 'Exam Administrator', 'is_system_role' => true]);
        $qManagerRole      = Role::create(['name' => 'Question Manager',   'is_system_role' => true]);
        $reviewerRole      = Role::create(['name' => 'Reviewer',           'is_system_role' => true]);
        $labManagerRole    = Role::create(['name' => 'Lab Manager',        'is_system_role' => true]);

        $this->superAdmin      = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->examAdmin       = User::factory()->create(['role_id' => $examAdminRole->id]);
        $this->questionManager = User::factory()->create(['role_id' => $qManagerRole->id]);
        $this->reviewer        = User::factory()->create(['role_id' => $reviewerRole->id]);
        $this->labManager      = User::factory()->create(['role_id' => $labManagerRole->id]);

        $this->subject = Subject::create([
            'name'       => 'English Language',
            'code'       => 'EN',
            'is_active'  => true,
            'created_by' => $this->superAdmin->id,
        ]);
    }

    // ================================================================
    // 1. INDEX
    // ================================================================

    #[Test]
    public function question_manager_can_list_questions(): void
    {
        Question::factory()->count(3)->create([
            'subject_id' => $this->subject->id,
            'created_by' => $this->questionManager->id,
        ]);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->getJson('/api/v1/admin/questions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.data');
    }

    #[Test]
    public function reviewer_can_list_questions(): void
    {
        $response = $this->actingAs($this->reviewer, 'admin')
            ->getJson('/api/v1/admin/questions');

        $response->assertOk();
    }

    #[Test]
    public function lab_manager_cannot_list_questions(): void
    {
        $response = $this->actingAs($this->labManager, 'admin')
            ->getJson('/api/v1/admin/questions');

        $response->assertForbidden();
    }

    #[Test]
    public function unauthenticated_user_cannot_list_questions(): void
    {
        $response = $this->getJson('/api/v1/admin/questions');

        $response->assertUnauthorized();
    }

    // ================================================================
    // 2. STORE
    // ================================================================

    #[Test]
    public function question_manager_can_create_question(): void
    {
        $payload = $this->validQuestionPayload();

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.question_text', $payload['question_text'])
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('questions', [
            'question_text' => $payload['question_text'],
            'created_by'    => $this->questionManager->id,
            'status'        => 'draft',
        ]);

        $this->assertDatabaseCount('question_options', 4);

        $this->assertDatabaseHas('question_versions', [
            'version_number' => 1,
            'changed_by'     => $this->questionManager->id,
        ]);
    }

    #[Test]
    public function super_admin_can_create_question(): void
    {
        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/api/v1/admin/questions', $this->validQuestionPayload());

        $response->assertCreated();
    }

    #[Test]
    public function reviewer_cannot_create_question(): void
    {
        $response = $this->actingAs($this->reviewer, 'admin')
            ->postJson('/api/v1/admin/questions', $this->validQuestionPayload());

        $response->assertForbidden();
    }

    #[Test]
    public function create_question_requires_valid_subject(): void
    {
        $payload = $this->validQuestionPayload(['subject_id' => 9999]);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);
    }

    #[Test]
    public function create_question_requires_minimum_two_options(): void
    {
        $payload = $this->validQuestionPayload([
            'options' => [
                ['option_text' => 'Only one', 'is_correct' => true, 'display_order' => 1],
            ],
        ]);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->postJson('/api/v1/admin/questions', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['options']);
    }

    // ================================================================
    // 3. SHOW
    // ================================================================

    #[Test]
    public function question_manager_can_view_question(): void
    {
        $question = $this->createDraftQuestion();

        $response = $this->actingAs($this->questionManager, 'admin')
            ->getJson("/api/v1/admin/questions/{$question->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $question->id);
    }

    #[Test]
    public function lab_manager_cannot_view_question(): void
    {
        $question = $this->createDraftQuestion();

        $response = $this->actingAs($this->labManager, 'admin')
            ->getJson("/api/v1/admin/questions/{$question->id}");

        $response->assertForbidden();
    }

    // ================================================================
    // 4. UPDATE
    // ================================================================

    #[Test]
    public function question_manager_can_update_own_draft_question(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->putJson("/api/v1/admin/questions/{$question->id}", [
                'question_text' => 'Updated question text',
                'change_reason' => 'Fixed grammar',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('question_versions', [
            'question_id'    => $question->id,
            'version_number' => 2,
            'change_reason'  => 'Fixed grammar',
        ]);
    }

    #[Test]
    public function question_manager_cannot_update_others_question(): void
    {
        $question = $this->createDraftQuestion($this->superAdmin->id);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->putJson("/api/v1/admin/questions/{$question->id}", [
                'question_text' => 'Hacked text',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function question_manager_cannot_update_approved_question(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);
        $question->update(['status' => 'approved']);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->putJson("/api/v1/admin/questions/{$question->id}", [
                'question_text' => 'Try to edit approved',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function super_admin_can_update_any_question(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->putJson("/api/v1/admin/questions/{$question->id}", [
                'question_text' => 'Super admin edit',
            ]);

        $response->assertOk();
    }

    // ================================================================
    // 5. CHANGE STATUS
    // ================================================================

    #[Test]
    public function question_manager_can_submit_own_draft_for_review(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'under_review',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('questions', [
            'id'     => $question->id,
            'status' => 'under_review',
        ]);
    }

    #[Test]
    public function question_manager_cannot_submit_others_draft_for_review(): void
    {
        $question = $this->createDraftQuestion($this->superAdmin->id);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'under_review',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function reviewer_can_approve_question_under_review(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);
        $question->update(['status' => 'under_review']);

        $response = $this->actingAs($this->reviewer, 'admin')
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('questions', [
            'id'          => $question->id,
            'status'      => 'approved',
            'reviewed_by' => $this->reviewer->id,
        ]);
    }

    #[Test]
    public function reviewer_cannot_approve_draft_question(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);

        $response = $this->actingAs($this->reviewer, 'admin')
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function invalid_status_transition_returns_422(): void
    {
        $question = $this->createDraftQuestion($this->superAdmin->id);
        $question->update(['status' => 'approved']);

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'archived',
            ]);

        $response->assertUnprocessable();
    }

    // ================================================================
    // 6. DESTROY
    // ================================================================

    #[Test]
    public function super_admin_can_delete_question(): void
    {
        $question = $this->createDraftQuestion();

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->deleteJson("/api/v1/admin/questions/{$question->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('questions', ['id' => $question->id]);
    }

    #[Test]
    public function question_manager_cannot_delete_question(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->deleteJson("/api/v1/admin/questions/{$question->id}");

        $response->assertForbidden();
    }

    // ================================================================
    // 7. VERSIONS
    // ================================================================

    #[Test]
    public function can_view_question_versions(): void
    {
        $question = $this->createDraftQuestion($this->questionManager->id);

        $response = $this->actingAs($this->questionManager, 'admin')
            ->getJson("/api/v1/admin/questions/{$question->id}/versions");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function lab_manager_cannot_view_versions(): void
    {
        $question = $this->createDraftQuestion();

        $response = $this->actingAs($this->labManager, 'admin')
            ->getJson("/api/v1/admin/questions/{$question->id}/versions");

        $response->assertForbidden();
    }

    // ================================================================
    // Helpers
    // ================================================================

   private function createDraftQuestion(?int $createdBy = null): Question
{
    $question = Question::create([
        'subject_id'    => $this->subject->id,
        'section_name'  => 'Grammar',
        'question_type' => 'single',
        'question_text' => 'She _____ to university every day.',
        'difficulty'    => 'medium',
        'score'         => 2,
        'status'        => 'draft',
        'created_by'    => $createdBy ?? $this->superAdmin->id,
    ]);

    QuestionOption::create(['question_id' => $question->id, 'option_text' => 'go',    'is_correct' => false, 'display_order' => 1]);
    QuestionOption::create(['question_id' => $question->id, 'option_text' => 'goes',  'is_correct' => true,  'display_order' => 2]);
    QuestionOption::create(['question_id' => $question->id, 'option_text' => 'going', 'is_correct' => false, 'display_order' => 3]);
    QuestionOption::create(['question_id' => $question->id, 'option_text' => 'gone',  'is_correct' => false, 'display_order' => 4]);

    // ساخت Version اول — مانند store API
    $version = \App\Models\QuestionVersion::create([
        'question_id'      => $question->id,
        'version_number'   => 1,
        'question_text'    => $question->question_text,
        'question_type'    => $question->question_type,
        'section_name'     => $question->section_name,
        'difficulty'       => $question->difficulty,
        'options_snapshot' => [],
        'changed_by'       => $createdBy ?? $this->superAdmin->id,
        'change_reason'    => 'Initial version',
    ]);

    $question->update(['current_version_id' => $version->id]);

    return $question->fresh();
}

    private function validQuestionPayload(array $overrides = []): array
    {
        return array_merge([
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'question_type' => 'single',
            'question_text' => 'Choose the correct form of the verb.',
            'difficulty'    => 'medium',
            'score'         => 2,
            'options'       => [
                ['option_text' => 'go',    'is_correct' => false, 'display_order' => 1],
                ['option_text' => 'goes',  'is_correct' => true,  'display_order' => 2],
                ['option_text' => 'going', 'is_correct' => false, 'display_order' => 3],
                ['option_text' => 'gone',  'is_correct' => false, 'display_order' => 4],
            ],
        ], $overrides);
    }
}