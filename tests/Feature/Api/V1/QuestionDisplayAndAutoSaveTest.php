<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\ExamSnapshotQuestion;
use App\Models\Lab;
use App\Models\Question;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های خودکار فاز ۲: نمایش سوالات و موتور ذخیره خودکار پاسخ‌ها (Auto-Save).
 */
class QuestionDisplayAndAutoSaveTest extends TestCase
{
    use RefreshDatabase;

    private function createExamWithSnapshotBundle(): array
    {
        $admin = User::first() ?? User::factory()->create();

        $subject = Subject::create([
            'code' => 'SUBJ_DISP_' . uniqid(),
            'name' => 'مضمون تست ذخیره پاسخ',
        ]);

        $exam = Exam::create([
            'subject_id'       => $subject->id,
            'title'            => 'آزمون تستی فاز ۲',
            'status'           => 'published',
            'duration_minutes' => 60,
            'total_marks'      => 100,
            'pass_marks'       => 50,
            'created_by'       => $admin->id,
        ]);

        $section = ExamSection::create([
            'exam_id'        => $exam->id,
            'name'           => 'بخش عمومی',
            'question_count' => 2,
            'total_marks'    => 100,
            'display_order'  => 1,
        ]);

        $lab = Lab::create([
            'name'     => 'لابراتوار مرکزی',
            'location' => 'طبقه اول',
        ]);

        $computer = Computer::create([
            'lab_id'        => $lab->id,
            'computer_code' => 'PC-AUTO-' . uniqid(),
            'ip_address'    => '10.0.9.1',
            'status'        => 'exam',
        ]);

        $shift = Shift::create([
            'exam_id'    => $exam->id,
            'lab_id'     => $lab->id,
            'name'       => 'شیفت صبح',
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addHour(),
            'capacity'   => 30,
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'KND_SAVE_' . uniqid(),
            'name'           => 'داوطلب تست ذخیره',
            'username'       => 'cand_save_' . uniqid(),
            'password'       => Hash::make('secret123'),
            'status'         => 'in_progress',
            'checked_in_at'  => now()->subMinutes(5),
        ]);

        $assignment = CandidateAssignment::create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $exam->id,
            'shift_id'     => $shift->id,
            'lab_id'       => $lab->id,
            'computer_id'  => $computer->id,
        ]);

        $snapshot = ExamSnapshot::create([
            'exam_id'      => $exam->id,
            'version'      => 1,
            'is_immutable' => true,
        ]);

        // ایجاد ۲ سوال در بانک سوالات برای traceability
        $rawQ1 = Question::create([
            'subject_id'    => $subject->id,
            'section_name'  => 'بخش عمومی',
            'question_text' => 'پایتخت افغانستان کدام شهر است؟',
            'question_type' => 'multiple_choice',
            'status'        => 'approved',
            'created_by'    => $admin->id,
        ]);

        $rawQ2 = Question::create([
            'subject_id'    => $subject->id,
            'section_name'  => 'بخش عمومی',
            'question_text' => 'توضیح مختصر در خصوص الگوریتم؟',
            'question_type' => 'short_answer',
            'status'        => 'approved',
            'created_by'    => $admin->id,
        ]);

        // سوال اول: چندگزینه‌ای همراه با گزینه‌ها (شامل فیلد محرمانه is_correct)
        $qSnapshot1 = ExamSnapshotQuestion::create([
            'exam_snapshot_id'       => $snapshot->id,
            'question_id'            => $rawQ1->id,
            'exam_section_id'        => $section->id,
            'display_order'          => 1,
            'score'                  => 50.00,
            'question_text_snapshot' => 'پایتخت افغانستان کدام شهر است؟',
            'question_type_snapshot' => 'multiple_choice',
            'options_snapshot'       => [
                ['id' => 101, 'option_text' => 'کابل', 'is_correct' => true, 'display_order' => 1],
                ['id' => 102, 'option_text' => 'هرات', 'is_correct' => false, 'display_order' => 2],
                ['id' => 103, 'option_text' => 'مزارشریف', 'is_correct' => false, 'display_order' => 3],
            ],
            'passage_snapshot'       => null,
            'media_snapshot'         => null,
        ]);

        // سوال دوم: تشریحی
        $qSnapshot2 = ExamSnapshotQuestion::create([
            'exam_snapshot_id'       => $snapshot->id,
            'question_id'            => $rawQ2->id,
            'exam_section_id'        => $section->id,
            'display_order'          => 2,
            'score'                  => 50.00,
            'question_text_snapshot' => 'توضیح مختصر در خصوص الگوریتم؟',
            'question_type_snapshot' => 'short_answer',
            'options_snapshot'       => [],
            'passage_snapshot'       => null,
            'media_snapshot'         => null,
        ]);

        $attempt = Attempt::create([
            'candidate_id'            => $candidate->id,
            'exam_id'                 => $exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id'        => $snapshot->id,
            'computer_id'             => $computer->id,
            'ip_address'              => '10.0.9.1',
            'started_at'              => now()->subMinutes(10),
            'expires_at'              => now()->addMinutes(50),
            'last_activity_at'        => now(),
            'status'                  => 'in_progress',
            'session_token'           => Str::random(40),
        ]);

        return compact('candidate', 'exam', 'shift', 'lab', 'computer', 'assignment', 'snapshot', 'attempt', 'qSnapshot1', 'qSnapshot2');
    }

    #[Test]
    public function test_01_correct_question_format_is_returned_to_candidate(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->getJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/state");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.questions')
            ->assertJsonStructure([
                'data' => [
                    'questions' => [
                        '*' => [
                            'id',
                            'display_order',
                            'score',
                            'question_text_snapshot',
                            'question_type_snapshot',
                            'options_snapshot',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function test_02_correct_options_displayed_without_exposing_is_correct(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->getJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/state");

        $response->assertStatus(200);

        $jsonString = $response->getContent();

        // هیچ ردی از کلید is_correct یا پاسخ صحیح نباید در پاسخ به داوطلب وجود داشته باشد
        $this->assertStringNotContainsString('"is_correct":true', $jsonString);
        $this->assertStringNotContainsString('"is_correct":false', $jsonString);
        $this->assertStringNotContainsString('isCorrect', $jsonString);
    }

    #[Test]
    public function test_03_candidate_cannot_access_another_candidates_attempt_state(): void
    {
        $bundle1 = $this->createExamWithSnapshotBundle();

        $candidateOther = Candidate::create([
            'candidate_code' => 'KND_OTHER_' . uniqid(),
            'name'           => 'متقاضی متفرقه',
            'username'       => 'cand_other_' . uniqid(),
            'password'       => Hash::make('secret123'),
        ]);

        $response = $this->actingAs($candidateOther, 'candidate')
            ->getJson("/api/v1/candidate/attempts/{$bundle1['attempt']->id}/state");

        $response->assertStatus(403);
    }

    #[Test]
    public function test_04_candidate_cannot_save_answer_for_unrelated_question(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        // سوال متعلق به یک اسنپ‌شات دیگر
        $otherSnapshot = ExamSnapshot::create([
            'exam_id'      => $bundle['exam']->id,
            'version'      => 99,
            'is_immutable' => true,
        ]);

        $otherQuestion = ExamSnapshotQuestion::create([
            'exam_snapshot_id'       => $otherSnapshot->id,
            'question_id'            => 1,
            'exam_section_id'        => 1,
            'display_order'          => 1,
            'score'                  => 10,
            'question_text_snapshot' => 'سوال نامربوط',
            'question_type_snapshot' => 'short_answer',
            'options_snapshot'       => [],
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $otherQuestion->id,
                'idempotency_key'           => 'key_' . uniqid(),
                'answer_text'               => 'پاسخ تست',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_05_valid_answer_saves_successfully(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => 'idemp_key_valid_1',
                'selected_option_ids'       => [101],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('attempt_answers', [
            'attempt_id'                => $bundle['attempt']->id,
            'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
            'idempotency_key'           => 'idemp_key_valid_1',
        ]);
    }

    #[Test]
    public function test_06_repeated_idempotency_key_does_not_create_duplicate_records(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        $key = 'idemp_key_repeat_1';

        // ذخیره اول
        $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => $key,
                'selected_option_ids'       => [101],
            ]);

        // ارسال مجدد با همان idempotency_key (مثلاً retry شبکه)
        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => $key,
                'selected_option_ids'       => [101],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('duplicate', true);

        // دقیقا ۱ رکورد باید در دیتابیس وجود داشته باشد
        $this->assertEquals(1, AttemptAnswer::where('attempt_id', $bundle['attempt']->id)->count());
    }

    #[Test]
    public function test_07_different_answers_update_the_same_logical_record(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        // انتخاب اول: گزینه ۱۰۱
        $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => 'key_opt_1',
                'selected_option_ids'       => [101],
            ]);

        // تغییر پاسخ داوطلب به گزینه ۱۰۲
        $res = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => 'key_opt_2',
                'selected_option_ids'       => [102],
            ]);

        $res->assertStatus(200);

        // همچنان فقط ۱ رکورد برای این سوال وجود دارد اما مقدار آن به ۱۰۲ بروزرسانی شده است
        $this->assertEquals(1, AttemptAnswer::where('attempt_id', $bundle['attempt']->id)->count());

        $record = AttemptAnswer::where('attempt_id', $bundle['attempt']->id)->first();
        $this->assertEquals([102], $record->selected_option_ids);
        $this->assertEquals(1, $record->changed_count);
    }

    #[Test]
    public function test_08_invalid_option_id_is_rejected(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        // ارسال گزینه با شناسه ۹۹۹ که متعلق به گزینه‌های سوال ۱ نیست
        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => 'key_invalid_opt',
                'selected_option_ids'       => [999],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_09_expired_attempt_cannot_save_answer(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        // منقضی کردن زمان تلاش
        $bundle['attempt']->update([
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => 'key_exp',
                'selected_option_ids'       => [101],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'auto_submitted');
    }

    #[Test]
    public function test_10_completed_attempt_cannot_save_answer(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        $bundle['attempt']->update([
            'status'       => 'completed',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => 'key_comp',
                'selected_option_ids'       => [101],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function test_11_concurrent_or_sequential_saves_do_not_corrupt_records(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($bundle['candidate'], 'candidate')
                ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                    'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                    'idempotency_key'           => "seq_key_{$i}",
                    'selected_option_ids'       => [101],
                ]);
        }

        // یک رکورد با شمارنده تغییرات ۳
        $this->assertEquals(1, AttemptAnswer::where('attempt_id', $bundle['attempt']->id)->count());
    }

    #[Test]
    public function test_12_previously_saved_answers_load_correctly_on_resume(): void
    {
        $bundle = $this->createExamWithSnapshotBundle();

        // ذخیره پاسخ سوال اول
        $this->actingAs($bundle['candidate'], 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/answer", [
                'exam_snapshot_question_id' => $bundle['qSnapshot1']->id,
                'idempotency_key'           => 'key_resume_test',
                'selected_option_ids'       => [101],
            ]);

        // فراخوانی state (که هنگام رزومه بارگذاری می‌شود)
        $response = $this->actingAs($bundle['candidate'], 'candidate')
            ->getJson("/api/v1/candidate/attempts/{$bundle['attempt']->id}/state");

        $response->assertStatus(200)
            ->assertJsonPath("data.answers.{$bundle['qSnapshot1']->id}.exam_snapshot_question_id", $bundle['qSnapshot1']->id)
            ->assertJsonPath("data.answers.{$bundle['qSnapshot1']->id}.selected_option_ids", [101]);
    }
}
