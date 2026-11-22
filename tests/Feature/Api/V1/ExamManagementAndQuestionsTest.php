<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\Faculty;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamManagementAndQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Subject $subject;
    private Subject $otherSubject;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $faculty = Faculty::create(['name' => 'Engineering', 'code' => 'ENG']);

        $this->subject = Subject::create([
            'name' => 'Computer Networks',
            'code' => 'NET101',
            'faculty_id' => $faculty->id,
            'is_active' => true,
        ]);

        $this->otherSubject = Subject::create([
            'name' => 'Database Systems',
            'code' => 'DBS101',
            'faculty_id' => $faculty->id,
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin Boss',
            'username' => 'admin_boss',
            'email' => 'admin@azmoon.local',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
    }

    private function createQuestion(Subject $subject, string $status = 'approved', float $score = 2.0): Question
    {
        $q = Question::create([
            'subject_id' => $subject->id,
            'section_name' => 'General',
            'question_type' => 'single',
            'question_text' => 'Sample test question text?',
            'difficulty' => 'medium',
            'topic' => 'General',
            'score' => $score,
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);

        QuestionOption::create([
            'question_id' => $q->id,
            'option_text' => 'Option 1',
            'is_correct' => true,
            'order_seq' => 1,
        ]);
        QuestionOption::create([
            'question_id' => $q->id,
            'option_text' => 'Option 2',
            'is_correct' => false,
            'order_seq' => 2,
        ]);

        return $q;
    }

    public function test_can_create_exam_with_validated_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/exams', [
                'subject_id' => $this->subject->id,
                'title' => 'Final Exam Networks',
                'description' => 'Comprehensive exam',
                'duration_minutes' => 90,
                'total_marks' => 100,
                'pass_marks' => 60,
                'randomize_questions' => true,
                'randomize_options' => true,
                'sections' => [
                    ['name' => 'Routing', 'question_count' => 25, 'total_marks' => 50],
                    ['name' => 'Switching', 'question_count' => 25, 'total_marks' => 50],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('exams', [
            'title' => 'Final Exam Networks',
            'status' => 'draft',
            'duration_minutes' => 90,
        ]);

        $this->assertDatabaseCount('exam_sections', 2);
    }

    public function test_available_questions_returns_only_approved_questions_of_same_subject(): void
    {
        $exam = Exam::create([
            'subject_id' => $this->subject->id,
            'title' => 'Test Exam',
            'duration_minutes' => 60,
            'total_marks' => 50,
            'pass_marks' => 30,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $approvedQ1 = $this->createQuestion($this->subject, 'approved');
        $approvedQ2 = $this->createQuestion($this->subject, 'active');
        $draftQ     = $this->createQuestion($this->subject, 'draft');
        $reviewQ    = $this->createQuestion($this->subject, 'under_review');
        $rejectedQ  = $this->createQuestion($this->subject, 'rejected');
        $otherSubQ  = $this->createQuestion($this->otherSubject, 'approved');

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/exams/{$exam->id}/available-questions");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $returnedIds = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($approvedQ1->id, $returnedIds);
        $this->assertContains($approvedQ2->id, $returnedIds);
        $this->assertNotContains($draftQ->id, $returnedIds);
        $this->assertNotContains($reviewQ->id, $returnedIds);
        $this->assertNotContains($rejectedQ->id, $returnedIds);
        $this->assertNotContains($otherSubQ->id, $returnedIds);
    }

    public function test_can_sync_questions_for_exam(): void
    {
        $exam = Exam::create([
            'subject_id' => $this->subject->id,
            'title' => 'Midterm Exam',
            'duration_minutes' => 60,
            'total_marks' => 20,
            'pass_marks' => 10,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $q1 = $this->createQuestion($this->subject, 'approved', 5.0);
        $q2 = $this->createQuestion($this->subject, 'approved', 7.5);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/exams/{$exam->id}/questions", [
                'question_ids' => [$q1->id, $q2->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2)
            ->assertJsonPath('total_score', 12.5);

        $this->assertDatabaseHas('exam_questions', [
            'exam_id' => $exam->id,
            'question_id' => $q1->id,
        ]);
        $this->assertDatabaseHas('exam_questions', [
            'exam_id' => $exam->id,
            'question_id' => $q2->id,
        ]);
    }

    public function test_cannot_attach_unapproved_or_wrong_subject_questions(): void
    {
        $exam = Exam::create([
            'subject_id' => $this->subject->id,
            'title' => 'Validation Exam',
            'duration_minutes' => 60,
            'total_marks' => 20,
            'pass_marks' => 10,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $rejectedQ = $this->createQuestion($this->subject, 'rejected');
        $otherSubQ = $this->createQuestion($this->otherSubject, 'approved');

        $res1 = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/exams/{$exam->id}/questions", [
                'question_ids' => [$rejectedQ->id],
            ]);
        $res1->assertStatus(422);

        $res2 = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/exams/{$exam->id}/questions", [
                'question_ids' => [$otherSubQ->id],
            ]);
        $res2->assertStatus(422);
    }

    public function test_can_remove_question_from_exam(): void
    {
        $exam = Exam::create([
            'subject_id' => $this->subject->id,
            'title' => 'Remove Question Exam',
            'duration_minutes' => 60,
            'total_marks' => 20,
            'pass_marks' => 10,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $q = $this->createQuestion($this->subject, 'approved');
        $exam->questions()->attach($q->id, ['display_order' => 1, 'score' => 2.0]);

        $this->assertDatabaseHas('exam_questions', ['exam_id' => $exam->id, 'question_id' => $q->id]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/exams/{$exam->id}/questions/{$q->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('exam_questions', ['exam_id' => $exam->id, 'question_id' => $q->id]);
    }

    public function test_cannot_modify_questions_of_running_exam(): void
    {
        $exam = Exam::create([
            'subject_id' => $this->subject->id,
            'title' => 'Running Exam',
            'duration_minutes' => 60,
            'total_marks' => 20,
            'pass_marks' => 10,
            'status' => 'running',
            'created_by' => $this->admin->id,
        ]);

        $q = $this->createQuestion($this->subject, 'approved');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/exams/{$exam->id}/questions", [
                'question_ids' => [$q->id],
            ]);

        // بر اساس ExamPolicy آزمون در وضعیت running قابل به‌روزرسانی نیست و با 403 رد می‌شود
        $response->assertStatus(403);
    }

    public function test_complete_lifecycle_from_question_to_exam_snapshot_integrity(): void
    {
        // 1. ساخت سوال اولیه در وضعیت draft
        $q = Question::create([
            'subject_id' => $this->subject->id,
            'section_name' => 'General',
            'question_type' => 'single',
            'question_text' => 'What is TCP/IP?',
            'difficulty' => 'easy',
            'topic' => 'Basics',
            'score' => 5.0,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        QuestionOption::create([
            'question_id' => $q->id,
            'option_text' => 'Protocol Suite',
            'is_correct' => true,
            'order_seq' => 1,
        ]);
        QuestionOption::create([
            'question_id' => $q->id,
            'option_text' => 'Hardware',
            'is_correct' => false,
            'order_seq' => 2,
        ]);

        // 2. ارسال به under_review
        $q->update(['status' => 'under_review']);

        // 3. رد با دلیل توسط بازبین
        $rejectRes = $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/questions/{$q->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'گزینه‌ها ناکافی هستند.',
            ]);
        $rejectRes->assertOk();
        $q->refresh();
        $this->assertEquals('rejected', $q->status);
        $this->assertEquals('گزینه‌ها ناکافی هستند.', $q->rejection_reason);

        // 4. ساخت آزمون
        $exam = Exam::create([
            'subject_id' => $this->subject->id,
            'title' => 'Lifecycle Exam',
            'duration_minutes' => 60,
            'total_marks' => 10,
            'pass_marks' => 5,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        // 5. سوال ردشده نباید قابل انتخاب در آزمون باشد
        $badSync = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/exams/{$exam->id}/questions", [
                'question_ids' => [$q->id],
            ]);
        $badSync->assertStatus(422);

        // 6. طراح سوال را اصلاح و ارسال، سپس بازبین تأیید می‌کند
        $q->update(['status' => 'under_review']);
        $approveRes = $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/questions/{$q->id}/status", [
                'status' => 'approved',
            ]);
        $approveRes->assertOk();
        $q->refresh();
        $this->assertEquals('approved', $q->status);
        $this->assertNull($q->rejection_reason);

        // 7. اکنون سوال تأییدشده با موفقیت به آزمون اضافه می‌شود
        $goodSync = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/exams/{$exam->id}/questions", [
                'question_ids' => [$q->id],
            ]);
        $goodSync->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertDatabaseHas('exam_questions', [
            'exam_id' => $exam->id,
            'question_id' => $q->id,
        ]);

        // 8. تغییر وضعیت آزمون به published و انجماد Snapshot
        $exam->update(['status' => 'approved']);
        $publishRes = $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/exams/{$exam->id}/status", [
                'status' => 'published',
            ]);
        $publishRes->assertOk();

        // بررسی اینکه اسنپ‌شات منجمد ایجاد شده است
        $this->assertDatabaseHas('exam_snapshots', ['exam_id' => $exam->id]);
        $this->assertDatabaseHas('exam_snapshot_questions', ['question_id' => $q->id]);

        // 9. تغییر بعدی سوال در بانک سوالات (مثلاً غیرفعال شدن) اسنپ‌شات آزمون قبلی را خراب نمی‌کند
        $q->update(['status' => 'inactive', 'question_text' => 'MODIFIED TEXT AFTER PUBLISH']);
        $this->assertDatabaseHas('exam_snapshot_questions', [
            'question_id' => $q->id,
            'question_text_snapshot' => 'What is TCP/IP?',
        ]);
    }
}
