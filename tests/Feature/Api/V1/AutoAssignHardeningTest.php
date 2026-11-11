<?php
namespace Tests\Feature\Api\V1;
use App\Models\Attempt;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\ExamSnapshotQuestion;
use App\Models\Lab;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
/**
 * سخت‌سازی heartbeat و بررسی رفتار expires_at و remainingSeconds.
 * چرا این فایل: اثبات مستقل P1 بدون تغییر state و answer و submit و Attempt.php.
 * توجه: این تست‌ها همزمانی واقعی را اثبات نمی‌کنند.
 */
class AutoAssignHardeningTest extends TestCase
{
    use RefreshDatabase;
    /**
     * پرچم شکست اجباری برای تست rollback.
     * چرا static: شنونده مدل فقط وقتی خطا بدهد که این تست فعال باشد.
     */
    public static $forceComputerFailure = false;
    /**
     * ساخت داده پایه مشابه AttemptTest.
     * چرا: start واقعی نیاز به assignment و snapshot معتبر دارد.
     */
    private function makeCandidate(): array
    {
        $lab = Lab::factory()->create();
        $computer = Computer::factory()->create([
            'lab_id' => $lab->id,
            'status' => 'online',
        ]);
        $exam = Exam::factory()->create([
            'status' => 'published',
            'duration_minutes' => 60,
        ]);
        $shift = Shift::factory()->create([
            'exam_id' => $exam->id,
            'lab_id' => $lab->id,
        ]);
        $candidate = Candidate::factory()->create([
            'status' => 'present',
        ]);
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id' => $exam->id,
            'shift_id' => $shift->id,
            'lab_id' => $lab->id,
            'computer_id' => $computer->id,
        ]);
        $snapshot = ExamSnapshot::factory()->create([
            'exam_id' => $exam->id,
            'is_immutable' => true,
        ]);
        $section = ExamSection::factory()->create([
            'exam_id' => $exam->id,
        ]);
        ExamSnapshotQuestion::factory()->count(3)->create([
            'exam_snapshot_id' => $snapshot->id,
            'exam_section_id' => $section->id,
        ]);
        return compact('candidate', 'exam', 'assignment', 'snapshot', 'computer');
    }
    /**
     * شروع واقعی آزمون و گرفتن شناسه Attempt.
     * چرا: به‌جای فکتوری مستقیم، مسیر واقعی start استفاده می‌شود.
     */
    private function startAttemptId($candidate): int
    {
        $res = $this->actingAs($candidate, 'candidate')->postJson('/api/v1/candidate/attempts/start');
        $res->assertSuccessful();
        $id = $res->json('data.attempt.id');
        $this->assertNotNull($id);
        return (int) $id;
    }
    /**
     * heartbeat فعال باید Attempt و Computer را با هم به‌روز کند.
     * چرا: اثبات مسیر موفق P1.
     */
    #[Test]
    public function heartbeat_active_attempt_updates_attempt_and_computer(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $computer = $data['computer'];
        $oldAttempt = now()->subHour()->startOfSecond();
        $oldSeen = now()->subHour()->startOfSecond();
        $computer->update(['last_seen_at' => $oldSeen]);
        $attemptId = $this->startAttemptId($candidate);
        Attempt::whereKey($attemptId)->update(['last_heartbeat_at' => $oldAttempt]);
        $res = $this->actingAs($candidate, 'candidate')->postJson("/api/v1/candidate/attempts/{$attemptId}/heartbeat");
        $res->assertStatus(200);
        $res->assertJsonStructure(['success', 'status', 'remaining_seconds', 'server_time']);
        $this->assertIsInt($res->json('remaining_seconds'));
        $freshAttempt = Attempt::findOrFail($attemptId);
        $freshComputer = Computer::findOrFail($computer->id);
        $this->assertNotNull($freshAttempt->last_heartbeat_at);
        $this->assertTrue($freshAttempt->last_heartbeat_at->greaterThan($oldAttempt));
        $this->assertSame('connected', $freshAttempt->connection_status);
        $this->assertSame('exam', $freshComputer->status);
        $this->assertTrue($freshComputer->last_seen_at->greaterThan($oldSeen));
    }
    /**
     * disconnected باید به in_progress برگردد.
     * چرا: بخشی از قرارداد heartbeat است.
     */
    #[Test]
    public function heartbeat_disconnected_becomes_in_progress(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $attemptId = $this->startAttemptId($candidate);
        Attempt::whereKey($attemptId)->update(['status' => 'disconnected', 'connection_status' => 'disconnected']);
        $res = $this->actingAs($candidate, 'candidate')->postJson("/api/v1/candidate/attempts/{$attemptId}/heartbeat");
        $res->assertStatus(200);
        $this->assertSame('in_progress', $res->json('status'));
        $this->assertSame('in_progress', Attempt::findOrFail($attemptId)->status);
    }
    /**
     * Attempt غیرفعال باید 422 بدهد و هیچ‌چیز را تغییر ندهد.
     * چرا: اثبات جلوگیری از exam گیرکرده؛ completed مستقیم در DB تنظیم می‌شود چون submit فعلاً خراب است.
     */
    #[Test]
    public function heartbeat_completed_attempt_returns_422_without_touching_computer(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $computer = $data['computer'];
        $attemptId = $this->startAttemptId($candidate);
        $oldBeat = now()->subHours(2)->startOfSecond();
        $oldSeen = now()->subHours(2)->startOfSecond();
        Attempt::whereKey($attemptId)->update(['status' => 'completed', 'last_heartbeat_at' => $oldBeat]);
        Computer::whereKey($computer->id)->update(['status' => 'online', 'last_seen_at' => $oldSeen]);
        $this->assertSame('online', Computer::findOrFail($computer->id)->status);
        $res = $this->actingAs($candidate, 'candidate')->postJson("/api/v1/candidate/attempts/{$attemptId}/heartbeat");
        $res->assertStatus(422);
        $freshAttempt = Attempt::findOrFail($attemptId);
        $freshComputer = Computer::findOrFail($computer->id);
        $this->assertSame('completed', $freshAttempt->status);
        $this->assertTrue($freshAttempt->last_heartbeat_at->equalTo($oldBeat));
        $this->assertSame('online', $freshComputer->status);
        $this->assertTrue($freshComputer->last_seen_at->equalTo($oldSeen));
    }
    /**
     * پس از انتقال فقط مقصد باید exam شود.
     * چرا: اثبات بازخوانی computer_id و دست‌نخوردن مبدأ؛ انتقال مستقیم در DB شبیه‌سازی می‌شود.
     */
    #[Test]
    public function heartbeat_after_transfer_touches_only_destination(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $source = $data['computer'];
        $attemptId = $this->startAttemptId($candidate);
        $oldSource = now()->subHour()->startOfSecond();
        $oldDest = now()->subHour()->startOfSecond();
        $dest = Computer::factory()->create(['lab_id' => $source->lab_id, 'status' => 'online', 'last_seen_at' => $oldDest]);
        $source->update(['status' => 'maintenance', 'last_seen_at' => $oldSource]);
        Attempt::whereKey($attemptId)->update(['computer_id' => $dest->id]);
        $res = $this->actingAs($candidate, 'candidate')->postJson("/api/v1/candidate/attempts/{$attemptId}/heartbeat");
        $res->assertStatus(200);
        $freshSource = Computer::findOrFail($source->id);
        $freshDest = Computer::findOrFail($dest->id);
        $this->assertSame('exam', $freshDest->status);
        $this->assertTrue($freshDest->last_seen_at->greaterThan($oldDest));
        $this->assertSame('maintenance', $freshSource->status);
        $this->assertTrue($freshSource->last_seen_at->equalTo($oldSource));
        $this->assertSame($dest->id, Attempt::findOrFail($attemptId)->computer_id);
    }
    /**
     * داوطلب دیگر باید 403 بگیرد و هیچ‌چیز تغییر نکند.
     * چرا: اثبات امنیت مالکیت.
     */
    #[Test]
    public function heartbeat_other_candidate_forbidden_without_change(): void
    {
        $data = $this->makeCandidate();
        $owner = $data['candidate'];
        $computer = $data['computer'];
        $attemptId = $this->startAttemptId($owner);
        $other = Candidate::factory()->create(['status' => 'present']);
        $oldBeat = now()->subHour()->startOfSecond();
        $oldSeen = now()->subHour()->startOfSecond();
        Attempt::whereKey($attemptId)->update(['last_heartbeat_at' => $oldBeat]);
        $computer->update(['last_seen_at' => $oldSeen, 'status' => 'exam']);
        $res = $this->actingAs($other, 'candidate')->postJson("/api/v1/candidate/attempts/{$attemptId}/heartbeat");
        $res->assertStatus(403);
        $this->assertTrue(Attempt::findOrFail($attemptId)->last_heartbeat_at->equalTo($oldBeat));
        $this->assertTrue(Computer::findOrFail($computer->id)->last_seen_at->equalTo($oldSeen));
    }
    /**
     * اگر update کامپیوتر شکست بخورد، update Attempt هم باید rollback شود.
     * چرا: اثبات اتمی بودن تراکنش P1.
     */
    #[Test]
    public function heartbeat_rolls_back_attempt_when_computer_update_fails(): void
    {
        $data = $this->makeCandidate();
        $candidate = $data['candidate'];
        $computer = $data['computer'];
        $attemptId = $this->startAttemptId($candidate);
        $oldBeat = now()->subHour()->startOfSecond();
        $oldSeen = now()->subHour()->startOfSecond();
        Attempt::whereKey($attemptId)->update(['last_heartbeat_at' => $oldBeat]);
        $computer->update(['last_seen_at' => $oldSeen, 'status' => 'exam']);
        Computer::updating(function ($model) {
            if (AutoAssignHardeningTest::$forceComputerFailure) {
                throw new \RuntimeException('forced-computer-failure');
            }
        });
        self::$forceComputerFailure = true;
        try {
            $res = $this->actingAs($candidate, 'candidate')->postJson("/api/v1/candidate/attempts/{$attemptId}/heartbeat");
            $res->assertStatus(500);
        } finally {
            self::$forceComputerFailure = false;
        }
        $this->assertTrue(Attempt::findOrFail($attemptId)->last_heartbeat_at->equalTo($oldBeat));
        $this->assertTrue(Computer::findOrFail($computer->id)->last_seen_at->equalTo($oldSeen));
    }
    /**
     * isExpired با expires_at برابر null باید Error بدهد.
     * چرا: رفتار فرض نمی‌شود و مستقیم اندازه‌گیری می‌شود.
     */
    #[Test]
    public function isExpired_with_null_expires_at_throws(): void
    {
        $m = new Attempt();
        $m->expires_at = null;
        $this->expectException(\Error::class);
        $m->isExpired();
    }
    /**
     * remainingSeconds برای Attempt فعال باید عدد صحیح باشد.
     * چرا: نوع خروجی با تست اثبات می‌شود.
     */
    #[Test]
    public function remainingSeconds_returns_int_for_active_attempt(): void
    {
        $data = $this->makeCandidate();
        $attemptId = $this->startAttemptId($data['candidate']);
        $v = Attempt::findOrFail($attemptId)->remainingSeconds();
        $this->assertIsInt($v);
        $this->assertGreaterThanOrEqual(0, $v);
    }
    /**
     * remainingSeconds با expires_at برابر null: یا int اثبات می‌شود یا کلاس و پیام خطا ثبت می‌شود.
     * چرا: هیچ رفتاری فرض نمی‌شود.
     */
    #[Test]
    public function remainingSeconds_with_null_expires_at_returns_int(): void
    {
        $m = new Attempt();
        $m->expires_at = null;
        try {
            $v = $m->remainingSeconds();
        } catch (\Throwable $e) {
            $this->fail('remainingSeconds(null) throws ' . get_class($e) . ': ' . $e->getMessage());
        }
        $this->assertIsInt($v);
    }
}