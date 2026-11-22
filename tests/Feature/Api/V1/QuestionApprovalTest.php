<?php

namespace Tests\Feature\Api\V1;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Faculty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;
    private User $questionManager;
    private User $unauthorizedUser;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $reviewerRole = Role::firstOrCreate(['name' => 'Reviewer']);
        $qmRole = Role::firstOrCreate(['name' => 'Question Manager']);
        $labRole = Role::firstOrCreate(['name' => 'Lab Manager']);

        $faculty = Faculty::create(['name' => 'Literature', 'code' => 'LIT']);
        $this->subject = Subject::create([
            'name' => 'English Grammar',
            'code' => 'ENG201',
            'faculty_id' => $faculty->id,
            'is_active' => true,
        ]);

        $this->reviewer = User::create([
            'name' => 'Sara Nazari',
            'username' => 'reviewer_sara',
            'email' => 'reviewer@azmoon.local',
            'password' => bcrypt('password'),
            'role_id' => $reviewerRole->id,
            'is_active' => true,
        ]);

        $this->questionManager = User::create([
            'name' => 'Karim Yousufi',
            'username' => 'qm_karim',
            'email' => 'qm@azmoon.local',
            'password' => bcrypt('password'),
            'role_id' => $qmRole->id,
            'is_active' => true,
        ]);

        $this->unauthorizedUser = User::create([
            'name' => 'Ahmad Lab',
            'username' => 'lab_ahmad',
            'email' => 'lab@azmoon.local',
            'password' => bcrypt('password'),
            'role_id' => $labRole->id,
            'is_active' => true,
        ]);
    }

    private function createQuestion(string $status = 'under_review'): Question
    {
        $question = Question::create([
            'subject_id' => $this->subject->id,
            'section_name' => 'Grammar',
            'question_type' => 'single',
            'question_text' => 'Choose the correct preposition.',
            'difficulty' => 'medium',
            'topic' => 'Prepositions',
            'score' => 2.0,
            'status' => $status,
            'created_by' => $this->questionManager->id,
        ]);

        QuestionOption::create([
            'question_id' => $question->id,
            'option_text' => 'in',
            'is_correct' => true,
            'order_seq' => 1,
        ]);

        QuestionOption::create([
            'question_id' => $question->id,
            'option_text' => 'at',
            'is_correct' => false,
            'order_seq' => 2,
        ]);

        return $question;
    }

    public function test_can_list_questions_under_review_and_counts(): void
    {
        $this->createQuestion('under_review');
        $this->createQuestion('draft');
        $this->createQuestion('approved');

        $response = $this->actingAs($this->reviewer)
            ->getJson('/api/v1/admin/questions?status=under_review');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('counts.under_review', 1)
            ->assertJsonPath('counts.draft', 1)
            ->assertJsonPath('counts.approved', 1);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('under_review', $data[0]['status']);
    }

    public function test_reviewer_can_approve_question(): void
    {
        $question = $this->createQuestion('under_review');

        $response = $this->actingAs($this->reviewer)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $question->refresh();
        $this->assertEquals('approved', $question->status);
        $this->assertEquals($this->reviewer->id, $question->reviewed_by);
        $this->assertNull($question->rejection_reason);
    }

    public function test_reviewer_can_reject_question_to_draft_with_reason(): void
    {
        $question = $this->createQuestion('under_review');

        $response = $this->actingAs($this->reviewer)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'draft',
                'rejection_reason' => 'گزینه صحیح به اشتباه علامت‌گذاری شده است.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $question->refresh();
        // طبق SRS رد سوال باید وضعیت آن را به draft برگرداند همراه با ثبت دلیل رد
        $this->assertEquals('draft', $question->status);
        $this->assertEquals($this->reviewer->id, $question->reviewed_by);
        $this->assertEquals('گزینه صحیح به اشتباه علامت‌گذاری شده است.', $question->rejection_reason);
    }

    public function test_question_manager_can_resubmit_draft_question_for_review(): void
    {
        $question = $this->createQuestion('draft');
        $question->update(['rejection_reason' => 'لطفاً گزینه دوم را اصلاح کنید.']);

        $response = $this->actingAs($this->questionManager)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'under_review',
            ]);

        $response->assertOk();
        $question->refresh();
        $this->assertEquals('under_review', $question->status);
    }

    public function test_cannot_approve_or_reject_already_approved_question(): void
    {
        $question = $this->createQuestion('approved');

        // سوال تأییدشده نمی‌تواند مستقیماً بدون بازبینی به پیش‌نویس بازگردد
        $response = $this->actingAs($this->reviewer)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'draft',
                'rejection_reason' => 'انتقال نامعتبر',
            ]);

        $response->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_approve_questions(): void
    {
        $question = $this->createQuestion('under_review');

        $response = $this->actingAs($this->unauthorizedUser)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'approved',
            ]);

        $response->assertStatus(403);
    }

    public function test_can_activate_approved_question(): void
    {
        $question = $this->createQuestion('approved');

        $response = $this->actingAs($this->reviewer)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'active',
            ]);

        $response->assertOk();
        $question->refresh();
        $this->assertEquals('active', $question->status);
    }

    public function test_reviewer_can_approve_all_under_review_questions(): void
    {
        $q1 = $this->createQuestion('under_review');
        $q2 = $this->createQuestion('under_review');
        $draftQ = $this->createQuestion('draft');

        $response = $this->actingAs($this->reviewer)
            ->postJson('/api/v1/admin/questions/approve-all');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('approved_count', 2);

        $q1->refresh();
        $q2->refresh();
        $draftQ->refresh();

        $this->assertEquals('approved', $q1->status);
        $this->assertEquals($this->reviewer->id, $q1->reviewed_by);
        $this->assertEquals('approved', $q2->status);
        $this->assertEquals('draft', $draftQ->status);
    }

    public function test_unauthorized_user_cannot_approve_all(): void
    {
        $this->createQuestion('under_review');

        $response = $this->actingAs($this->unauthorizedUser)
            ->postJson('/api/v1/admin/questions/approve-all');

        $response->assertStatus(403);
    }
}
