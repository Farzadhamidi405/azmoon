<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\AttemptResult;
use App\Models\AttemptSectionResult;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های جامع فاز ۳: ثبت نهایی آزمون و محاسبه اتمیک کارنامه و نتایج.
 */
class ExamSubmissionAndResultTest extends TestCase
{
    use RefreshDatabase;

    private function createAttemptWithQuestions(array $examOverrides = []): array
    {
        $admin = User::first() ?? User::factory()->create();

        $subject = Subject::create([
            'code' => 'SUBJ_RES_' . uniqid(),
            'name' => 'مضمون سنجش نتایج',
        ]);

        $exam = Exam::create(array_merge([
            'subject_id'              => $subject->id,
            'title'                   => 'آزمون تستی فاز ۳',
            'status'                  => 'published',
            'duration_minutes'        => 60,
            'total_marks'             => 100,
            'pass_marks'              => 50,
            'show_result_immediately' => true,
            'created_by'              => $admin->id,
        ], $examOverrides));

        $sectionA = ExamSection::create([
            'exam_id'       => $exam->id,
            'name'          => 'بخش عمومی',
            'order'         => 1,
            'section_order' => 1,
            'question_count'=> 2,
            'total_marks'   => 50,
        ]);

        $sectionB = ExamSection::create([
            'exam_id'       => $exam->id,
            'name'          => 'بخش اختصاصی',
            'order'         => 2,
            'section_order' => 2,
            'question_count'=> 2,
            'total_marks'   => 50,
        ]);

        $snapshot = ExamSnapshot::create([
            'exam_id'       => $exam->id,
            'version'       => 1,
            'snapshot_hash' => Str::random(32),
            'structure'     => ['sections' => []],
            'created_at'    => now(),
        ]);

        // ایجاد ۴ سوال تستی در اسنپ‌شات (هر سوال ۲۵ نمره)
        // سوال ۱ در بخش A
        $q1 = Question::create([
            'subject_id'    => $subject->id,
            'section_name'  => 'عمومی',
            'question_type' => 'single',
            'question_text' => 'سوال اول بخش عمومی',
            'score'         => 25.00,
            'created_by'    => $admin->id,
        ]);

        $snapQ1 = ExamSnapshotQuestion::create([
            'exam_snapshot_id'       => $snapshot->id,
            'exam_section_id'        => $sectionA->id,
            'question_id'            => $q1->id,
            'question_type_snapshot' => 'single',
            'question_text_snapshot' => 'سوال اول بخش عمومی',
            'score'                  => 25.00,
            'display_order'          => 1,
            'order_in_section'       => 1,
            'options_snapshot'       => [
                ['id' => 101, 'option_text' => 'گزینه الف', 'is_correct' => true],
                ['id' => 102, 'option_text' => 'گزینه ب', 'is_correct' => false],
            ],
        ]);

        // سوال ۲ در بخش A
        $q2 = Question::create([
            'subject_id'    => $subject->id,
            'section_name'  => 'عمومی',
            'question_type' => 'single',
            'question_text' => 'سوال دوم بخش عمومی',
            'score'         => 25.00,
            'created_by'    => $admin->id,
        ]);

        $snapQ2 = ExamSnapshotQuestion::create([
            'exam_snapshot_id'       => $snapshot->id,
            'exam_section_id'        => $sectionA->id,
            'question_id'            => $q2->id,
            'question_type_snapshot' => 'single',
            'question_text_snapshot' => 'سوال دوم بخش عمومی',
            'score'                  => 25.00,
            'display_order'          => 2,
            'order_in_section'       => 2,
            'options_snapshot'       => [
                ['id' => 201, 'option_text' => 'گزینه یک', 'is_correct' => false],
                ['id' => 202, 'option_text' => 'گزینه دو', 'is_correct' => true],
            ],
        ]);

        // سوال ۳ در بخش B
        $q3 = Question::create([
            'subject_id'    => $subject->id,
            'section_name'  => 'اختصاصی',
            'question_type' => 'single',
            'question_text' => 'سوال اول بخش اختصاصی',
            'score'         => 25.00,
            'created_by'    => $admin->id,
        ]);

        $snapQ3 = ExamSnapshotQuestion::create([
            'exam_snapshot_id'       => $snapshot->id,
            'exam_section_id'        => $sectionB->id,
            'question_id'            => $q3->id,
            'question_type_snapshot' => 'single',
            'question_text_snapshot' => 'سوال اول بخش اختصاصی',
            'score'                  => 25.00,
            'display_order'          => 3,
            'order_in_section'       => 1,
            'options_snapshot'       => [
                ['id' => 301, 'option_text' => 'گزینه اول', 'is_correct' => true],
                ['id' => 302, 'option_text' => 'گزینه دوم', 'is_correct' => false],
            ],
        ]);

        // سوال ۴ در بخش B
        $q4 = Question::create([
            'subject_id'    => $subject->id,
            'section_name'  => 'اختصاصی',
            'question_type' => 'single',
            'question_text' => 'سوال دوم بخش اختصاصی',
            'score'         => 25.00,
            'created_by'    => $admin->id,
        ]);

        $snapQ4 = ExamSnapshotQuestion::create([
            'exam_snapshot_id'       => $snapshot->id,
            'exam_section_id'        => $sectionB->id,
            'question_id'            => $q4->id,
            'question_type_snapshot' => 'single',
            'question_text_snapshot' => 'سوال دوم بخش اختصاصی',
            'score'                  => 25.00,
            'display_order'          => 4,
            'order_in_section'       => 2,
            'options_snapshot'       => [
                ['id' => 401, 'option_text' => 'گزینه الف', 'is_correct' => false],
                ['id' => 402, 'option_text' => 'گزینه ب', 'is_correct' => true],
            ],
        ]);

        $lab = Lab::create([
            'code'     => 'LAB_RES_' . uniqid(),
            'name'     => 'لابراتوار تست ۳',
            'capacity' => 10,
            'status'   => 'active',
        ]);

        $computer = Computer::create([
            'lab_id'        => $lab->id,
            'computer_code' => 'PC_RES_' . uniqid(),
            'ip_address'    => '192.168.30.' . rand(1, 250),
            'status'        => 'exam',
        ]);

        $candidate = Candidate::create([
            'candidate_code' => 'CAND_RES_' . uniqid(),
            'username'       => 'cand_u_' . uniqid(),
            'name'           => 'داوطلب فاز ۳',
            'father_name'    => 'احمد',
            'national_id'    => 'T3_' . uniqid(),
            'phone'          => '0799' . rand(100000, 999999),
            'password'       => Hash::make('password123'),
            'status'         => 'in_progress',
        ]);

        $shift = Shift::create([
            'exam_id'        => $exam->id,
            'lab_id'         => $lab->id,
            'name'           => 'شیفت صبح',
            'start_time'     => '2026-11-23 08:00:00',
            'end_time'       => '2026-11-23 10:00:00',
            'capacity'       => 10,
            'status'         => 'active',
        ]);

        $assignment = CandidateAssignment::create([
            'candidate_id'  => $candidate->id,
            'exam_id'       => $exam->id,
            'shift_id'      => $shift->id,
            'lab_id'        => $lab->id,
            'computer_id'   => $computer->id,
            'status'        => 'in_exam',
            'assigned_at'   => now(),
            'checked_in_at' => now(),
        ]);

        $attempt = Attempt::create([
            'candidate_id'            => $candidate->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_id'                 => $exam->id,
            'computer_id'             => $computer->id,
            'shift_id'                => $shift->id,
            'exam_snapshot_id'        => $snapshot->id,
            'ip_address'              => '192.168.30.10',
            'status'                  => 'in_progress',
            'connection_status'       => 'connected',
            'started_at'              => now(),
            'expires_at'              => now()->addMinutes(60),
            'last_heartbeat_at'       => now(),
            'last_activity_at'        => now(),
            'total_questions'         => 4,
        ]);

        return [
            'candidate'  => $candidate,
            'exam'       => $exam,
            'attempt'    => $attempt,
            'computer'   => $computer,
            'questions'  => [$snapQ1, $snapQ2, $snapQ3, $snapQ4],
            'sections'   => [$sectionA, $sectionB],
        ];
    }

    private function createAnswer(Attempt $attempt, ExamSnapshotQuestion $question, array $attributes = []): AttemptAnswer
    {
        return AttemptAnswer::create(array_merge([
            'attempt_id'                => $attempt->id,
            'exam_snapshot_question_id' => $question->id,
            'answered_at'               => now(),
            'idempotency_key'           => Str::uuid()->toString(),
        ], $attributes));
    }

    #[Test]
    public function candidate_can_submit_exam_and_results_are_calculated_atomically(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        // پاسخ دادن: سوال ۱ درست (نمره ۲۵)، سوال ۲ غلط (نمره ۰)، سوال ۳ درست (نمره ۲۵)، سوال ۴ بی‌پاسخ (نمره ۰)
        // جمع کل نمره: ۵۰ از ۱۰۰ -> حدنصاب ۵۰ است، پس باید pass شود
        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);
        $this->createAnswer($attempt, $questions[1], ['selected_option_ids' => [201]]);
        $this->createAnswer($attempt, $questions[2], ['selected_option_ids' => [301]]);
        // سوال ۴ بی‌پاسخ است

        $response = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status'  => 'completed',
            ]);

        $attempt->refresh();
        $this->assertEquals('completed', $attempt->status);
        $this->assertNotNull($attempt->submitted_at);
        $this->assertEquals(50.00, (float) $attempt->score);
        $this->assertEquals(2, $attempt->correct_count);
        $this->assertEquals(1, $attempt->wrong_count);

        // بررسی جدول attempt_results
        $result = AttemptResult::where('attempt_id', $attempt->id)->first();
        $this->assertNotNull($result);
        $this->assertEquals(50.00, (float) $result->total_score);
        $this->assertEquals(50.00, (float) $result->percentage);
        $this->assertEquals('pass', $result->pass_fail);

        // بررسی جدول ریزنمرات بخش‌ها
        $this->assertDatabaseHas('attempt_section_results', [
            'attempt_id'       => $attempt->id,
            'exam_section_id'  => $bundle['sections'][0]->id,
            'correct_count'    => 1,
            'wrong_count'      => 1,
            'unanswered_count' => 0,
            'score'            => 25.00,
        ]);

        $this->assertDatabaseHas('attempt_section_results', [
            'attempt_id'       => $attempt->id,
            'exam_section_id'  => $bundle['sections'][1]->id,
            'correct_count'    => 1,
            'wrong_count'      => 0,
            'unanswered_count' => 1,
            'score'            => 25.00,
        ]);
    }

    #[Test]
    public function submission_is_idempotent_and_returns_existing_result_without_duplicate_records(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);

        // ثبت بار اول
        $first = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit");

        $first->assertStatus(200)
            ->assertJson(['success' => true, 'status' => 'completed']);

        $this->assertEquals(1, AttemptResult::where('attempt_id', $attempt->id)->count());

        // ثبت تکراری (مثلاً دابل کلیک داوطلب یا ارسال مجدد شبکه)
        $second = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit");

        $second->assertStatus(200)
            ->assertJson([
                'success'   => true,
                'status'    => 'completed',
                'duplicate' => true,
            ]);

        // نباید رکورد کارنامه دومی ایجاد شده باشد
        $this->assertEquals(1, AttemptResult::where('attempt_id', $attempt->id)->count());
    }

    #[Test]
    public function candidate_cannot_save_answer_after_submission(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        // ثبت آزمون
        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        // تلاش برای تغییر پاسخ پس از ثبت نهایی
        $res = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/answer", [
                'question_id'         => $questions[0]->id,
                'selected_option_ids' => [101],
                'idempotency_key'     => Str::uuid()->toString(),
            ]);

        $res->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    #[Test]
    public function candidate_cannot_submit_another_candidates_attempt(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $attempt = $bundle['attempt'];

        $anotherCandidate = Candidate::create([
            'candidate_code' => 'CAND_OTHER_' . uniqid(),
            'username'       => 'cand_other_' . uniqid(),
            'name'           => 'داوطلب متفرقه',
            'father_name'    => 'علی',
            'national_id'    => 'OTHER_' . uniqid(),
            'phone'          => '0788' . rand(100000, 999999),
            'password'       => Hash::make('secret'),
            'status'         => 'in_progress',
        ]);

        $res = $this->actingAs($anotherCandidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit");

        $res->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'دسترسی غیرمجاز.']);
    }

    #[Test]
    public function auto_submit_on_expired_attempt_calculates_results(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        // تنظیم انقضا در زمان گذشته
        $attempt->update(['expires_at' => now()->subMinute()]);

        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);

        // داوطلب دکمه ثبت می‌زند، اما سیستم تشخیص انقضا داده و auto_submitted می‌کند
        $res = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit");

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status'  => 'auto_submitted',
            ]);

        $attempt->refresh();
        $this->assertEquals('auto_submitted', $attempt->status);
        $this->assertDatabaseHas('attempt_results', [
            'attempt_id'  => $attempt->id,
            'total_score' => 25.00,
        ]);
    }

    #[Test]
    public function artisan_command_auto_submits_all_expired_and_disconnected_attempts(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);

        // داوطلب قطع شده و زمانش گذشته است
        $attempt->update([
            'status'            => 'disconnected',
            'connection_status' => 'disconnected',
            'expires_at'        => now()->subMinutes(5),
        ]);

        $this->assertDatabaseMissing('attempt_results', ['attempt_id' => $attempt->id]);

        $exitCode = Artisan::call('attempts:auto-submit-expired');
        $this->assertEquals(0, $exitCode);

        $attempt->refresh();
        $this->assertEquals('auto_submitted', $attempt->status);
        $this->assertDatabaseHas('attempt_results', [
            'attempt_id'  => $attempt->id,
            'total_score' => 25.00,
        ]);
    }

    #[Test]
    public function pass_and_fail_status_is_determined_accurately_based_on_exam_pass_marks(): void
    {
        // آزمون با نمره قبولی ۶۰
        $bundle = $this->createAttemptWithQuestions(['pass_marks' => 60]);
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        // دو سوال صحیح = ۵۰ نمره (کمتر از ۶۰)
        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);
        $this->createAnswer($attempt, $questions[2], ['selected_option_ids' => [301]]);

        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $result = AttemptResult::where('attempt_id', $attempt->id)->first();
        $this->assertEquals('fail', $result->pass_fail);
    }

    #[Test]
    public function candidate_can_retrieve_their_own_result_without_leaking_correct_answer_keys(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);

        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit");

        $res = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/attempts/{$attempt->id}/result");

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'attempt_id'  => $attempt->id,
                    'total_score' => 25.00,
                    'percentage'  => 25.00,
                    'pass_fail'   => 'fail',
                ],
            ]);

        // مطمئن می‌شویم کلیدهای پاسخ سوالات در این اندپوینت افشا نشده است
        $json = $res->getContent();
        $this->assertStringNotContainsString('is_correct', $json);
        $this->assertStringNotContainsString('options_snapshot', $json);
    }

    #[Test]
    public function written_questions_are_not_falsely_marked_wrong_and_marked_for_manual_review(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        // تغییر سوال ۴ به تشریحی بدون گزینه
        $questions[3]->update([
            'question_type_snapshot' => 'short_answer',
            'options_snapshot'       => [],
        ]);

        // متقاضی به سوال ۴ پاسخ متنی می‌دهد
        $this->createAnswer($attempt, $questions[3], [
            'answer_text'         => 'این پاسخ تشریحی است.',
            'selected_option_ids' => null,
        ]);

        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $writtenAnswer = AttemptAnswer::where('attempt_id', $attempt->id)
            ->where('exam_snapshot_question_id', $questions[3]->id)
            ->first();

        // نباید به عنوان غلط (is_correct = false) ذخیره شده باشد، بلکه نال برای تصحیح دستی
        $this->assertNull($writtenAnswer->is_correct);
        $this->assertNull($writtenAnswer->score_awarded);

        // اندپوینت کارنامه نشان می‌دهد که آزمون دارای بخش در انتظار تصحیح دستی است
        $res = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/attempts/{$attempt->id}/result");

        $res->assertStatus(200)
            ->assertJsonPath('data.has_pending_manual', true);
    }

    #[Test]
    public function unanswered_questions_are_tallied_correctly_and_no_negative_marks_deducted(): void
    {
        $bundle = $this->createAttemptWithQuestions();
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        // فقط به سوال اول پاسخ صحیح می‌دهد و بقیه ۳ سوال بدون پاسخ می‌مانند
        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);

        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $attempt->refresh();
        $this->assertEquals(25.00, (float) $attempt->score);
        $this->assertEquals(1, $attempt->correct_count);
        $this->assertEquals(0, $attempt->wrong_count);

        $res = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/attempts/{$attempt->id}/result");

        $res->assertStatus(200);
        $this->assertEquals(25.00, (float) $res->json('data.total_score'));
        $this->assertEquals(1, $res->json('data.correct_count'));
        $this->assertEquals(0, $res->json('data.wrong_count'));
    }

    #[Test]
    public function auto_submit_command_is_registered_in_scheduler(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());

        $autoSubmitEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'attempts:auto-submit-expired');
        });

        $this->assertNotNull($autoSubmitEvent, 'دستور attempts:auto-submit-expired در لاراول اسکلولر ثبت نشده است.');
        $this->assertEquals('* * * * *', $autoSubmitEvent->expression);
        $this->assertTrue($autoSubmitEvent->withoutOverlapping);
    }

    #[Test]
    public function schedule_list_includes_auto_submit_command(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('attempts:auto-submit-expired', $output);
    }

    #[Test]
    public function hidden_results_do_not_expose_total_score_percentage_pass_fail_or_sections(): void
    {
        $bundle = $this->createAttemptWithQuestions(['show_result_immediately' => false]);
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);

        // ۱. بررسی محافظت در پاسخ submit
        $submitRes = $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit");

        $submitRes->assertStatus(200);
        $submitData = $submitRes->json('data');

        $this->assertFalse($submitData['show_result_immediately']);
        $this->assertArrayNotHasKey('total_score', $submitData);
        $this->assertArrayNotHasKey('percentage', $submitData);
        $this->assertArrayNotHasKey('pass_fail', $submitData);
        $this->assertArrayNotHasKey('correct_count', $submitData);
        $this->assertArrayNotHasKey('wrong_count', $submitData);
        $this->assertArrayNotHasKey('section_results', $submitData);

        // ۲. بررسی محافظت در درخواست مستقیم اندپوینت کارنامه /result
        $resultRes = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/attempts/{$attempt->id}/result");

        $resultRes->assertStatus(200);
        $resultData = $resultRes->json('data');

        $this->assertFalse($resultData['show_result_immediately']);
        $this->assertArrayNotHasKey('total_score', $resultData);
        $this->assertArrayNotHasKey('percentage', $resultData);
        $this->assertArrayNotHasKey('pass_fail', $resultData);
        $this->assertArrayNotHasKey('correct_count', $resultData);
        $this->assertArrayNotHasKey('wrong_count', $resultData);
        $this->assertArrayNotHasKey('section_results', $resultData);

        // بررسی فیلدهای غیرحساس که جهت تأیید دریافت پاسخنامه باید حفظ شوند
        $this->assertEquals($attempt->id, $resultData['attempt_id']);
        $this->assertEquals('آزمون تستی فاز ۳', $resultData['exam_title']);
        $this->assertEquals(100.00, (float) $resultData['total_marks']);
        $this->assertEquals(50.00, (float) $resultData['pass_marks']);
    }

    #[Test]
    public function visible_results_still_return_permitted_fields(): void
    {
        $bundle = $this->createAttemptWithQuestions(['show_result_immediately' => true]);
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        $this->createAnswer($attempt, $questions[0], ['selected_option_ids' => [101]]);

        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $res = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/attempts/{$attempt->id}/result");

        $res->assertStatus(200);
        $data = $res->json('data');

        $this->assertTrue($data['show_result_immediately']);
        $this->assertArrayHasKey('total_score', $data);
        $this->assertArrayHasKey('percentage', $data);
        $this->assertArrayHasKey('pass_fail', $data);
        $this->assertArrayHasKey('correct_count', $data);
        $this->assertArrayHasKey('wrong_count', $data);
        $this->assertArrayHasKey('section_results', $data);
        $this->assertEquals(25.00, (float) $data['total_score']);
    }

    #[Test]
    public function pending_manual_grading_is_not_presented_as_final_pass_fail(): void
    {
        $bundle = $this->createAttemptWithQuestions(['show_result_immediately' => true]);
        $candidate = $bundle['candidate'];
        $attempt = $bundle['attempt'];
        $questions = $bundle['questions'];

        $questions[3]->update([
            'question_type_snapshot' => 'short_answer',
            'options_snapshot'       => [],
        ]);

        $this->createAnswer($attempt, $questions[3], [
            'answer_text'         => 'پاسخ متنی تشریحی',
            'selected_option_ids' => null,
        ]);

        $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $res = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/attempts/{$attempt->id}/result");

        $res->assertStatus(200);
        $data = $res->json('data');

        $this->assertTrue($data['has_pending_manual']);
        $this->assertFalse($data['is_finalized']);
        $this->assertEquals('pending_manual_grading', $data['pass_fail']);
        $this->assertNotEquals('pass', $data['pass_fail']);
        $this->assertNotEquals('fail', $data['pass_fail']);
    }
}
