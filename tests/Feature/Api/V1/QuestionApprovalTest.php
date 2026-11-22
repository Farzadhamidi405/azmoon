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

    public function test_can_list_questions_under_review(): void
    {
        $this->createQuestion('under_review');
        $this->createQuestion('draft');
        $this->createQuestion('approved');

        $response = $this->actingAs($this->reviewer)
            ->getJson('/api/v1/admin/questions?status=under_review');

        $response->assertOk()
            ->assertJsonPath('success', true);

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

    public function test_reviewer_can_reject_question_with_reason(): void
    {
        $question = $this->createQuestion('under_review');

        $response = $this->actingAs($this->reviewer)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'گزینه صحیح به اشتباه علامت‌گذاری شده است.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $question->refresh();
        $this->assertEquals('rejected', $question->status);
        $this->assertEquals($this->reviewer->id, $question->reviewed_by);
        $this->assertEquals('گزینه صحیح به اشتباه علامت‌گذاری شده است.', $question->rejection_reason);
    }

    public function test_rejection_requires_reason(): void
    {
        $question = $this->createQuestion('under_review');

        $response = $this->actingAs($this->reviewer)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);
    }

    public function test_cannot_approve_or_reject_already_approved_question(): void
    {
        $question = $this->createQuestion('approved');

        // بازبین نباید بتواند سوالی که قبلاً تأیید شده را بدون بررسی مجدد تغییر وضعیت دهد
        $response = $this->actingAs($this->reviewer)
            ->patchJson("/api/v1/admin/questions/{$question->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'رد نامعتبر',
            ]);

        $response->assertStatus(403);
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

    public function test_can_filter_approved_and_rejected_questions(): void
    {
        $q1 = $this->createQuestion('approved');
        $q2 = $this->createQuestion('rejected');
        $q2->update(['rejection_reason' => 'متن سوال نامفهوم است']);

        $resApproved = $this->actingAs($this->reviewer)
            ->getJson('/api/v1/admin/questions?status=approved');

        $resApproved->assertOk();
        $this->assertCount(1, $resApproved->json('data.data'));
        $this->assertEquals($q1->id, $resApproved->json('data.data.0.id'));

        $resRejected = $this->actingAs($this->reviewer)
            ->getJson('/api/v1/admin/questions?status=rejected');

        $resRejected->assertOk();
        $this->assertCount(1, $resRejected->json('data.data'));
        $this->assertEquals($q2->id, $resRejected->json('data.data.0.id'));
        $this->assertEquals('متن سوال نامفهوم است', $resRejected->json('data.data.0.rejection_reason'));
    }
}
