<?php

namespace Tests\Feature\Api\V1;

use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\Lab;
use App\Models\Media;
use App\Models\Passage;
use App\Models\Question;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\User;
use App\Services\ExamSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های تضمین کیفیت برای قابلیت‌های اصلاح‌شده:
 * - ذخیره آزمون همراه با بخش‌ها (Exam Sections)
 * - ولیدیشن مجموع نمرات بخش‌ها
 * - API لیست نقش‌ها (Roles API)
 * - فیلتر موضوع بانک سوالات (Topic Filter)
 * - API تجمیعی داشبورد (Dashboard Stats)
 * - آپلود مدیا برای سوالات شنیداری (Media Upload)
 * - مدیریت متون درک مطلب (Passages API)
 * - سرویس اسنپ‌شات و شروع بدون خطا در Attempt Engine
 */
class SectionFixesAndGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $examAdmin;
    private User $questionManager;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'Super Admin', 'is_system_role' => true]);
        $examAdminRole  = Role::create(['name' => 'Exam Administrator', 'is_system_role' => true]);
        $qManagerRole   = Role::create(['name' => 'Question Manager', 'is_system_role' => true]);

        $this->superAdmin = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->examAdmin  = User::factory()->create(['role_id' => $examAdminRole->id]);
        $this->questionManager = User::factory()->create(['role_id' => $qManagerRole->id]);

        $this->subject = Subject::create([
            'name'       => 'English Test Subject',
            'code'       => 'ENG101',
            'is_active'  => true,
            'created_by' => $this->superAdmin->id,
        ]);
    }

    #[Test]
    public function exam_can_be_created_with_sections_in_a_single_transaction(): void
    {
        $payload = [
            'subject_id'              => $this->subject->id,
            'title'                   => 'Midterm English Exam',
            'description'             => 'Test Exam with Sections',
            'duration_minutes'        => 90,
            'total_marks'             => 100,
            'pass_marks'              => 50,
            'allow_back_navigation'   => true,
            'randomize_questions'     => true,
            'randomize_options'       => true,
            'show_result_immediately' => false,
            'sections'                => [
                ['name' => 'Grammar', 'question_count' => 20, 'total_marks' => 40, 'display_order' => 1],
                ['name' => 'Vocabulary', 'question_count' => 10, 'total_marks' => 20, 'display_order' => 2],
                ['name' => 'Reading', 'question_count' => 10, 'total_marks' => 20, 'display_order' => 3],
                ['name' => 'Listening', 'question_count' => 10, 'total_marks' => 20, 'display_order' => 4],
            ],
        ];

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/api/v1/admin/exams', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data.sections');

        $examId = $response->json('data.id');
        $this->assertDatabaseHas('exams', ['id' => $examId, 'title' => 'Midterm English Exam']);
        $this->assertDatabaseCount('exam_sections', 4);
    }

    #[Test]
    public function exam_creation_fails_if_sections_marks_sum_does_not_match_total_marks(): void
    {
        $payload = [
            'subject_id'              => $this->subject->id,
            'title'                   => 'Invalid Exam Marks',
            'duration_minutes'        => 60,
            'total_marks'             => 100,
            'pass_marks'              => 50,
            'sections'                => [
                ['name' => 'Grammar', 'question_count' => 10, 'total_marks' => 30],
                ['name' => 'Reading', 'question_count' => 10, 'total_marks' => 30],
            ],
        ];

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/api/v1/admin/exams', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('exams', ['title' => 'Invalid Exam Marks']);
    }

    #[Test]
    public function roles_api_returns_roles_with_permissions_for_admin(): void
    {
        $response = $this->actingAs($this->superAdmin, 'admin')
            ->getJson('/api/v1/admin/roles');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'name', 'permissions']]]);
    }

    #[Test]
    public function question_bank_correctly_filters_by_topic(): void
    {
        Question::factory()->create([
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'topic'         => 'Conditionals',
            'question_text' => 'If it rains, we will stay home.',
            'status'        => 'approved',
            'created_by'    => $this->superAdmin->id,
        ]);

        Question::factory()->create([
            'subject_id'    => $this->subject->id,
            'section_name'  => 'Grammar',
            'topic'         => 'Passive Voice',
            'question_text' => 'The book was written by John.',
            'status'        => 'approved',
            'created_by'    => $this->superAdmin->id,
        ]);

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->getJson('/api/v1/admin/questions?topic=Conditionals');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.topic', 'Conditionals');
    }

    #[Test]
    public function dashboard_stats_endpoint_returns_aggregated_metrics(): void
    {
        $response = $this->actingAs($this->superAdmin, 'admin')
            ->getJson('/api/v1/admin/dashboard-stats');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'published_exams',
                    'running_exams',
                    'candidates_count',
                    'in_progress_count',
                    'recent_activity',
                ],
            ]);
    }

    #[Test]
    public function media_can_be_uploaded_and_saved_to_private_disk(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('listening_audio.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/api/v1/admin/media', [
                'file' => $file,
                'type' => 'audio',
                'max_replay_count' => 2,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'audio');

        $filePath = $response->json('data.file_path');
        Storage::disk('local')->assertExists($filePath);
    }

    #[Test]
    public function passages_can_be_created_and_listed(): void
    {
        $payload = [
            'subject_id' => $this->subject->id,
            'title'      => 'A Passage on Natural Resources',
            'body_text'  => 'This is a long reading passage describing the natural resources of Kunduz province in northern Afghanistan.',
        ];

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/api/v1/admin/passages', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', $payload['title']);

        $this->assertDatabaseHas('passages', ['title' => $payload['title']]);
    }

    #[Test]
    public function snapshot_service_creates_immutable_snapshot_for_exam(): void
    {
        $exam = Exam::create([
            'subject_id'       => $this->subject->id,
            'title'            => 'Final Exam for Snapshot',
            'duration_minutes' => 60,
            'total_marks'      => 100,
            'pass_marks'       => 50,
            'status'           => 'published',
            'created_by'       => $this->superAdmin->id,
        ]);

        $exam->sections()->create([
            'name'           => 'Grammar',
            'question_count' => 10,
            'total_marks'    => 100,
            'display_order'  => 1,
        ]);

        Question::factory()->count(5)->create([
            'subject_id'   => $this->subject->id,
            'section_name' => 'Grammar',
            'status'       => 'approved',
            'created_by'   => $this->superAdmin->id,
        ]);

        $service = app(ExamSnapshotService::class);
        $snapshot = $service->findOrCreateSnapshot($exam);

        $this->assertInstanceOf(ExamSnapshot::class, $snapshot);
        $this->assertTrue($snapshot->is_immutable);
        $this->assertNotNull($snapshot->snapshot_hash);
        $this->assertGreaterThan(0, $snapshot->snapshotQuestions()->count());
    }

    #[Test]
    public function unauthenticated_user_cannot_access_listening_stream(): void
    {
        $media = Media::create([
            'type'             => 'audio',
            'file_path'        => 'media/audio/test.mp3',
            'allow_replay'     => false,
            'max_replay_count' => 1,
            'uploaded_by'      => $this->superAdmin->id,
        ]);

        $response = $this->getJson("/api/v1/media/{$media->id}/stream");
        $response->assertStatus(401);
    }

    #[Test]
    public function candidate_without_active_attempt_cannot_stream_audio(): void
    {
        $candidate = Candidate::factory()->create();

        $media = Media::create([
            'type'             => 'audio',
            'file_path'        => 'media/audio/test.mp3',
            'allow_replay'     => false,
            'max_replay_count' => 1,
            'uploaded_by'      => $this->superAdmin->id,
        ]);

        $response = $this->actingAs($candidate, 'candidate')
            ->getJson("/api/v1/media/{$media->id}/stream");

        $response->assertStatus(403);
    }
}
