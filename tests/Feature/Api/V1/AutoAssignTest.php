<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attempt;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Shift;
use App\Models\User;
use App\Services\AutoAssignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * تست‌های یکپارچه و جامع سخت‌سازی تخصیص خودکار (Step 14.6.5c)
 *
 * این کلاس سناریوهای ۱ تا ۱۱c و ۱۱e را روی دیتابیس تست (azmoon_test) پوشش می‌دهد:
 * - عدم تغییر دیتابیس در حالت Preview (confirmed=false)
 * - ثبت دقیق تخصیص‌ها، assigned_by و assigned_at در حالت Confirm (confirmed=true)
 * - عدم تخصیص یک کامپیوتر به دو داوطلب در یک شیفت
 * - عدم انتخاب کامپیوتر دارای Attempt فعال (شامل in_progress و disconnected)
 * - نادیده گرفتن داوطلب Soft-Deleted بدون خطا
 * - رعایت ظرفیت شیفت با احتساب صندلی رزرو شده داوطلب Soft-Deleted و عدم تخصیص مجدد کامپیوتر او
 * - حفظ تخصیص‌های قبلی شیفت
 * - Idempotency در اجرای مجدد Confirm
 * - اتمیک بودن و Rollback کامل در صورت بروز خطا یا نقض شرط whereNull('computer_id')
 * - هماهنگی کامل با SessionTransferController::store (تغییر وضعیت به exam و مسدودسازی کامپیوتر رزرو شده در شیفت)
 * - هماهنگی کامل با AttemptController::start (تغییر وضعیت به exam، رد کامپیوتر نامعتبر، و حفظ کامل قابلیت Resume قبل و بعد از SessionTransfer)
 */
class AutoAssignTest extends TestCase
{
    use RefreshDatabase;

    /**
     * بررسی امنیتی اتصال دیتابیس پیش از هر تست:
     * اجرای تست‌ها صرفاً روی دیتابیس تست (azmoon_test) مجاز است.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dbName = (string) DB::connection()->getDatabaseName();
        if ($dbName !== 'azmoon_test') {
            $this->fail("Security Guard: Tests must ONLY run against 'azmoon_test'. Current database: '{$dbName}'.");
        }
    }

    /**
     * سناریوی ۱: حالت پیش‌نمایش (confirmed=false) هیچ تغییری در دیتابیس ایجاد نمی‌کند.
     */
    public function test_01_preview_does_not_persist_any_changes(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-01', 'status' => 'online']);
        $comp2 = $this->createComputerRecord($labId, ['computer_code' => 'PC-02', 'status' => 'online']);

        $cand1 = $this->createCandidateRecord(['candidate_code' => 'CAND-101']);
        $cand2 = $this->createCandidateRecord(['candidate_code' => 'CAND-102']);

        $assign1 = $this->createAssignmentRecord($cand1, $examId, $shiftId, $labId, null);
        $assign2 = $this->createAssignmentRecord($cand2, $examId, $shiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $response = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", [
            'confirmed' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', false)
            ->assertJsonPath('mode', 'preview')
            ->assertJsonPath('data.summary.will_be_assigned', 2)
            ->assertJsonPath('data.summary.will_remain', 0);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign1,
            'computer_id' => null,
            'assigned_by' => null,
        ]);
        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign2,
            'computer_id' => null,
            'assigned_by' => null,
        ]);

        $this->assertSame('online', DB::table('computers')->where('id', $comp1)->value('status'));
        $this->assertSame('online', DB::table('computers')->where('id', $comp2)->value('status'));
    }

    /**
     * سناریوی ۲: حالت تأیید (confirmed=true) تخصیص‌ها را همراه با assigned_by و assigned_at ذخیره می‌کند.
     */
    public function test_02_confirm_persists_assignments_with_assigned_by_and_assigned_at(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-01', 'status' => 'online']);
        $comp2 = $this->createComputerRecord($labId, ['computer_code' => 'PC-02', 'status' => 'online']);

        $cand1 = $this->createCandidateRecord(['candidate_code' => 'CAND-201']);
        $cand2 = $this->createCandidateRecord(['candidate_code' => 'CAND-202']);

        $assign1 = $this->createAssignmentRecord($cand1, $examId, $shiftId, $labId, null);
        $assign2 = $this->createAssignmentRecord($cand2, $examId, $shiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $response = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", [
            'confirmed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('mode', 'confirmed')
            ->assertJsonPath('assigned', 2)
            ->assertJsonPath('data.summary.will_be_assigned', 2)
            ->assertJsonPath('data.summary.will_remain', 0);

        $row1 = DB::table('candidate_assignments')->where('id', $assign1)->first();
        $row2 = DB::table('candidate_assignments')->where('id', $assign2)->first();

        $this->assertNotNull($row1);
        $this->assertNotNull($row2);
        $this->assertSame($comp1, (int) $row1->computer_id);
        $this->assertSame($comp2, (int) $row2->computer_id);
        $this->assertSame((int) $admin->id, (int) $row1->assigned_by);
        $this->assertSame((int) $admin->id, (int) $row2->assigned_by);
        $this->assertNotNull($row1->assigned_at);
        $this->assertNotNull($row2->assigned_at);
    }

    /**
     * سناریوی ۳: یک کامپیوتر در یک شیفت هرگز به دو داوطلب تخصیص داده نمی‌شود.
     */
    public function test_03_single_computer_in_shift_is_never_assigned_to_two_candidates(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-ONLY-01', 'status' => 'online']);

        $cand1 = $this->createCandidateRecord(['candidate_code' => 'CAND-301']);
        $cand2 = $this->createCandidateRecord(['candidate_code' => 'CAND-302']);

        $assign1 = $this->createAssignmentRecord($cand1, $examId, $shiftId, $labId, null);
        $assign2 = $this->createAssignmentRecord($cand2, $examId, $shiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $response = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", [
            'confirmed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('data.summary.will_be_assigned', 1)
            ->assertJsonPath('data.summary.will_remain', 1)
            ->assertJsonPath('data.summary.available_computers', 1);

        $row1 = DB::table('candidate_assignments')->where('id', $assign1)->first();
        $row2 = DB::table('candidate_assignments')->where('id', $assign2)->first();

        $this->assertSame($comp1, (int) $row1->computer_id);
        $this->assertNull($row2->computer_id);
    }

    /**
     * سناریوی ۴: کامپیوتر دارای Attempt فعال (چه in_progress و چه disconnected) انتخاب نمی‌شود.
     */
    public function test_04_computer_with_active_attempt_in_progress_or_disconnected_is_excluded(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $otherExamId = $this->createExamRecord(['created_by' => $admin->id]);
        $snapshotId = $this->createExamSnapshotRecord($otherExamId);

        $otherShiftId = $this->createShiftRecord($otherExamId, $labId, ['capacity' => 10]);
        $targetShiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 10]);

        // سه کامپیوتر دارای Attempt فعال در شیفت دیگر (حتی اگر به هر دلیل status کامپیوتر online مانده باشد)
        $compInProgressConnected = $this->createComputerRecord($labId, ['computer_code' => 'PC-A1', 'status' => 'online']);
        $compInProgressDisconnectedConn = $this->createComputerRecord($labId, ['computer_code' => 'PC-A2', 'status' => 'online']);
        $compDisconnectedStatus = $this->createComputerRecord($labId, ['computer_code' => 'PC-A3', 'status' => 'online']);
        // یک کامپیوتر واقعاً آزاد
        $compFree = $this->createComputerRecord($labId, ['computer_code' => 'PC-A4', 'status' => 'online']);

        $activeCand1 = $this->createCandidateRecord(['candidate_code' => 'ACT-01']);
        $activeCand2 = $this->createCandidateRecord(['candidate_code' => 'ACT-02']);
        $activeCand3 = $this->createCandidateRecord(['candidate_code' => 'ACT-03']);

        $activeAssign1 = $this->createAssignmentRecord($activeCand1, $otherExamId, $otherShiftId, $labId, $compInProgressConnected);
        $activeAssign2 = $this->createAssignmentRecord($activeCand2, $otherExamId, $otherShiftId, $labId, $compInProgressDisconnectedConn);
        $activeAssign3 = $this->createAssignmentRecord($activeCand3, $otherExamId, $otherShiftId, $labId, $compDisconnectedStatus);

        $this->createAttemptRecord($activeCand1, $otherExamId, $activeAssign1, $snapshotId, $compInProgressConnected, [
            'status'            => 'in_progress',
            'connection_status' => 'connected',
        ]);
        $this->createAttemptRecord($activeCand2, $otherExamId, $activeAssign2, $snapshotId, $compInProgressDisconnectedConn, [
            'status'            => 'in_progress',
            'connection_status' => 'disconnected',
        ]);
        $this->createAttemptRecord($activeCand3, $otherExamId, $activeAssign3, $snapshotId, $compDisconnectedStatus, [
            'status'            => 'disconnected',
            'connection_status' => 'disconnected',
        ]);

        // دو داوطلب در شیفت هدف به دنبال کامپیوتر هستند
        $targetCand1 = $this->createCandidateRecord(['candidate_code' => 'TGT-01']);
        $targetCand2 = $this->createCandidateRecord(['candidate_code' => 'TGT-02']);
        $targetAssign1 = $this->createAssignmentRecord($targetCand1, $examId, $targetShiftId, $labId, null);
        $targetAssign2 = $this->createAssignmentRecord($targetCand2, $examId, $targetShiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $response = $this->postJson("/api/v1/admin/labs/shifts/{$targetShiftId}/auto-assign", [
            'confirmed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('data.summary.will_be_assigned', 1)
            ->assertJsonPath('data.summary.will_remain', 1);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $targetAssign1,
            'computer_id' => $compFree,
        ]);
        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $targetAssign2,
            'computer_id' => null,
        ]);
    }

    /**
     * سناریوی ۵: داوطلب Soft-Deleted تخصیص جدید نمی‌گیرد و باعث خطا نمی‌شود.
     */
    public function test_05_soft_deleted_candidate_is_skipped_without_error(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-01', 'status' => 'online']);
        $comp2 = $this->createComputerRecord($labId, ['computer_code' => 'PC-02', 'status' => 'online']);

        $deletedCand = $this->createCandidateRecord([
            'candidate_code' => 'DEL-501',
            'deleted_at'     => now(),
        ]);
        $activeCand = $this->createCandidateRecord([
            'candidate_code' => 'ACT-502',
            'deleted_at'     => null,
        ]);

        $delAssign = $this->createAssignmentRecord($deletedCand, $examId, $shiftId, $labId, null);
        $actAssign = $this->createAssignmentRecord($activeCand, $examId, $shiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $response = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", [
            'confirmed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('data.summary.total_unassigned', 1)
            ->assertJsonPath('data.summary.will_be_assigned', 1)
            ->assertJsonPath('data.summary.will_remain', 0);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $delAssign,
            'computer_id' => null,
        ]);
        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $actAssign,
            'computer_id' => $comp1,
        ]);
    }

    /**
     * سناریوی ۶: ظرفیت شیفت بر اساس کل ردیف‌های دارای computer_id در شیفت (حتی اگر داوطلب Soft-Deleted باشد)
     * رعایت می‌شود و کامپیوترِ داوطلبِ Soft-Deleted نیز به داوطلب دیگری داده نمی‌شود.
     */
    public function test_06_shift_capacity_and_occupied_computer_count_soft_deleted_assigned_rows(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        // ظرفیت شیفت = 2
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 2]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-01', 'status' => 'online']);
        $comp2 = $this->createComputerRecord($labId, ['computer_code' => 'PC-02', 'status' => 'online']);
        $comp3 = $this->createComputerRecord($labId, ['computer_code' => 'PC-03', 'status' => 'online']);

        // یک داوطلب که قبلاً PC-01 را گرفته و سپس Soft-Delete شده است (۱ صندلی از ظرفیت ۲ شیفت و PC-01 را اشغال کرده است)
        $softDeletedAssignedCand = $this->createCandidateRecord([
            'candidate_code' => 'SD-OCC-01',
            'deleted_at'     => now(),
        ]);
        $this->createAssignmentRecord($softDeletedAssignedCand, $examId, $shiftId, $labId, $comp1);

        // دو داوطلب فعال بدون کامپیوتر
        $cand1 = $this->createCandidateRecord(['candidate_code' => 'ACT-601']);
        $cand2 = $this->createCandidateRecord(['candidate_code' => 'ACT-602']);
        $assign1 = $this->createAssignmentRecord($cand1, $examId, $shiftId, $labId, null);
        $assign2 = $this->createAssignmentRecord($cand2, $examId, $shiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $response = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", [
            'confirmed' => true,
        ]);

        // ظرفیت باقی‌مانده شیفت فقط ۱ صندلی است (2 - 1 = 1) و PC-01 نیز اشغال است، پس فقط PC-02 به cand1 می‌رسد
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('data.shift.capacity', 2)
            ->assertJsonPath('data.summary.remaining_capacity', 1)
            ->assertJsonPath('data.summary.will_be_assigned', 1)
            ->assertJsonPath('data.summary.will_remain', 1);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign1,
            'computer_id' => $comp2,
        ]);
        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign2,
            'computer_id' => null,
        ]);
    }

    /**
     * سناریوی ۷: تخصیص‌های قبلی موجود در شیفت بدون تغییر حفظ می‌شوند.
     */
    public function test_07_existing_assignments_are_preserved(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-01', 'status' => 'online']);
        $comp2 = $this->createComputerRecord($labId, ['computer_code' => 'PC-02', 'status' => 'online']);

        $existingCand = $this->createCandidateRecord(['candidate_code' => 'EX-701']);
        $newCand = $this->createCandidateRecord(['candidate_code' => 'NW-702']);

        // داوطلب اول از قبل PC-02 را دارد
        $existingAssign = $this->createAssignmentRecord($existingCand, $examId, $shiftId, $labId, $comp2);
        $newAssign = $this->createAssignmentRecord($newCand, $examId, $shiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $response = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", [
            'confirmed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('data.summary.total_unassigned', 1)
            ->assertJsonPath('data.summary.will_be_assigned', 1)
            ->assertJsonPath('data.summary.will_remain', 0);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $existingAssign,
            'computer_id' => $comp2,
        ]);
        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $newAssign,
            'computer_id' => $comp1,
        ]);
    }

    /**
     * سناریوی ۸: اجرای مجدد Confirm کاملاً Idempotent است (assigned=0 و بدون تخصیص تکراری).
     */
    public function test_08_re_running_confirm_is_idempotent(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-01', 'status' => 'online']);
        $cand1 = $this->createCandidateRecord(['candidate_code' => 'IDM-801']);
        $assign1 = $this->createAssignmentRecord($cand1, $examId, $shiftId, $labId, null);

        $this->authenticateAsAdmin($admin);

        $firstResponse = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", ['confirmed' => true]);
        $firstResponse->assertStatus(200)
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('data.summary.will_be_assigned', 1);

        $secondResponse = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", ['confirmed' => true]);
        $secondResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('assigned', 0)
            ->assertJsonPath('data.summary.will_be_assigned', 0)
            ->assertJsonPath('data.summary.will_remain', 0)
            ->assertJsonPath('data.summary.remaining_capacity', 4);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign1,
            'computer_id' => $comp1,
        ]);
    }

    /**
     * سناریوی ۹: اتمیک بودن و Rollback کامل تراکنش در صورت بروز خطا یا نقض شرط whereNull('computer_id') در میانه تخصیص.
     */
    public function test_09_atomic_rollback_when_concurrent_modification_occurs_mid_transaction(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-01', 'status' => 'online']);
        $comp2 = $this->createComputerRecord($labId, ['computer_code' => 'PC-02', 'status' => 'online']);
        $comp3 = $this->createComputerRecord($labId, ['computer_code' => 'PC-03', 'status' => 'online']);

        $cand1 = $this->createCandidateRecord(['candidate_code' => 'ATM-901']);
        $cand2 = $this->createCandidateRecord(['candidate_code' => 'ATM-902']);

        $assign1 = $this->createAssignmentRecord($cand1, $examId, $shiftId, $labId, null);
        $assign2 = $this->createAssignmentRecord($cand2, $examId, $shiftId, $labId, null);

        // به محض اینکه ردیف اول (assign1) آپدیت شد، ردیف دوم (assign2) را قبل از رسیدن حلقه به آن، غیر تهی می‌کنیم
        // تا شرط whereNull('computer_id') در ردیف دوم، affected=0 برگرداند و کل تراکنش (شامل ردیف اول) Rollback شود.
        $hookTriggered = false;
        DB::listen(function ($query) use (&$hookTriggered, $assign2, $comp3) {
            $sql = strtolower($query->sql);
            if (!$hookTriggered && str_contains($sql, 'update') && str_contains($sql, 'candidate_assignments')) {
                $hookTriggered = true;
                DB::table('candidate_assignments')
                    ->where('id', $assign2)
                    ->update(['computer_id' => $comp3]);
            }
        });

        $service = app(AutoAssignService::class);

        $exceptionThrown = false;
        try {
            $service->confirm($shiftId, (int) $admin->id);
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown, 'Expected RuntimeException when conditional update affected 0 rows.');

        // به دلیل Rollback کامل تراکنش، هم ردیف اول و هم تغییر درون تراکنش باید به حالت اولیه (null) برگشته باشند
        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign1,
            'computer_id' => null,
        ]);
        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign2,
            'computer_id' => null,
        ]);
    }

    /**
     * سناریوی ۱۰: SessionTransferController::store وضعیت کامپیوتر مقصد را به exam تغییر می‌دهد تا AutoAssign آن را انتخاب نکند،
     * و همچنین انتقال به کامپیوتری که در همان شیفت برای داوطلب دیگری رزرو شده یا Attempt فعال دارد را با 422 رد می‌کند.
     */
    public function test_10_session_transfer_sets_destination_to_exam_and_prevents_reserved_or_active_target(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $snapshotId = $this->createExamSnapshotRecord($examId);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 10]);

        $compSource = $this->createComputerRecord($labId, ['computer_code' => 'PC-SRC', 'status' => 'exam']);
        $compReserved = $this->createComputerRecord($labId, ['computer_code' => 'PC-RSV', 'status' => 'online']);
        $compFreeDest = $this->createComputerRecord($labId, ['computer_code' => 'PC-DST', 'status' => 'online']);

        $candActive = $this->createCandidateRecord(['candidate_code' => 'TR-ACT']);
        $candReserved = $this->createCandidateRecord(['candidate_code' => 'TR-RSV']);
        $candUnassigned = $this->createCandidateRecord(['candidate_code' => 'TR-UNA']);

        $assignActive = $this->createAssignmentRecord($candActive, $examId, $shiftId, $labId, $compSource);
        $assignReserved = $this->createAssignmentRecord($candReserved, $examId, $shiftId, $labId, $compReserved);
        $assignUnassigned = $this->createAssignmentRecord($candUnassigned, $examId, $shiftId, $labId, null);

        $attemptId = $this->createAttemptRecord($candActive, $examId, $assignActive, $snapshotId, $compSource, [
            'status'            => 'in_progress',
            'connection_status' => 'connected',
        ]);

        $this->authenticateAsAdmin($admin);

        // الف) تلاش برای انتقال به کامپیوتر رزرو شده برای داوطلب دیگر در همان شیفت -> باید با 422 رد شود
        $rejectResponse = $this->postJson("/api/v1/admin/attempts/{$attemptId}/session-transfers", [
            'attempt_id'          => $attemptId,
            'to_computer_id'      => $compReserved,
            'reason'              => 'Testing reserved computer rejection',
            'source_Handling' => 'maintenance',
        ]);
        $rejectResponse->assertStatus(422);

        // ب) انتقال به کامپیوتر آزاد -> باید موفق شود و وضعیت کامپیوتر مقصد را به exam تغییر دهد
        $successResponse = $this->postJson("/api/v1/admin/attempts/{$attemptId}/session-transfers", [
            'attempt_id'          => $attemptId,
            'to_computer_id'      => $compFreeDest,
            'reason'              => 'Hardware issue on source PC',
            'source_Handling' => 'maintenance',
        ]);
        $successResponse->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertSame('exam', DB::table('computers')->where('id', $compFreeDest)->value('status'));
        $this->assertSame('maintenance', DB::table('computers')->where('id', $compSource)->value('status'));
        $this->assertSame($compFreeDest, (int) DB::table('attempts')->where('id', $attemptId)->value('computer_id'));

        // ج) اجرای Auto-Assign پس از انتقال: کامپیوتر مقصد (exam) و کامپیوتر رزرو شده نباید به داوطلب سوم داده شوند
        $autoAssignResponse = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", [
            'confirmed' => true,
        ]);
        $autoAssignResponse->assertStatus(200)
            ->assertJsonPath('assigned', 0)
            ->assertJsonPath('data.summary.will_be_assigned', 0)
            ->assertJsonPath('data.summary.will_remain', 1);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assignUnassigned,
            'computer_id' => null,
        ]);
    }

    /**
     * سناریوی ۱۱a: پس از اجرای AttemptController::start، وضعیت کامپیوتر به exam تغییر کرده
     * و Auto-Assign بعدی آن کامپیوتر را به داوطلب دیگری تخصیص نمی‌دهد.
     */
    public function test_11a_after_attempt_start_auto_assign_does_not_select_the_in_exam_computer(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $this->createExamSnapshotRecord($examId);

        $shift1 = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);
        $shift2 = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-S1', 'status' => 'online']);

        $cand1Id = $this->createCandidateRecord(['candidate_code' => 'ST-101']);
        $cand2Id = $this->createCandidateRecord(['candidate_code' => 'ST-102']);

        $this->createAssignmentRecord($cand1Id, $examId, $shift1, $labId, $comp1);
        $assign2 = $this->createAssignmentRecord($cand2Id, $examId, $shift2, $labId, null);

        $candidate1 = Candidate::findOrFail($cand1Id);
        $this->authenticateAsCandidate($candidate1);

        $startResponse = $this->postJson('/api/v1/candidate/attempts/start', [
            'exam_id' => $examId,
        ]);
        $startResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_resume', false);

        // وضعیت کامپیوتر پس از شروع آزمون باید به exam تغییر کرده باشد
        $this->assertSame('exam', DB::table('computers')->where('id', $comp1)->value('status'));

        // اکنون ادمین روی شیفت دوم Auto-Assign اجرا می‌کند؛ PC-S1 نباید انتخاب شود
        $this->authenticateAsAdmin($admin);
        $autoAssignResponse = $this->postJson("/api/v1/admin/labs/shifts/{$shift2}/auto-assign", [
            'confirmed' => true,
        ]);
        $autoAssignResponse->assertStatus(200)
            ->assertJsonPath('assigned', 0)
            ->assertJsonPath('data.summary.will_be_assigned', 0)
            ->assertJsonPath('data.summary.will_remain', 1);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign2,
            'computer_id' => null,
        ]);
    }

    /**
     * سناریوی ۱۱b: هنگام شروع Attempt جدید، اگر کامپیوتر تخصیص‌یافته online نباشد، یا Attempt فعال دیگری روی آن باشد،
     * یا اصلاً computer_id تخصیص نیافته باشد (null)، متد start با کد 422 درخواست را رد می‌کند.
     */
    public function test_11b_attempt_start_rejects_unassigned_non_online_or_occupied_computer_with_422(): void
    {
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord();
        $otherExamId = $this->createExamRecord();
        $snapshotId = $this->createExamSnapshotRecord($examId);
        $otherSnapshotId = $this->createExamSnapshotRecord($otherExamId);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 10]);

        // ۱. داوطلب بدون کامپیوتر (computer_id = null)
        $candNoCompId = $this->createCandidateRecord(['candidate_code' => 'REJ-NULL']);
        $this->createAssignmentRecord($candNoCompId, $examId, $shiftId, $labId, null);

        $this->authenticateAsCandidate(Candidate::findOrFail($candNoCompId));
        $resNull = $this->postJson('/api/v1/candidate/attempts/start', ['exam_id' => $examId]);
        $resNull->assertStatus(422);

        // ۲. داوطلب با کامپیوتر در وضعیت maintenance (غیر online)
        $compMaint = $this->createComputerRecord($labId, ['computer_code' => 'PC-MNT', 'status' => 'maintenance']);
        $candMaintId = $this->createCandidateRecord(['candidate_code' => 'REJ-MNT']);
        $this->createAssignmentRecord($candMaintId, $examId, $shiftId, $labId, $compMaint);

        $this->authenticateAsCandidate(Candidate::findOrFail($candMaintId));
        $resMaint = $this->postJson('/api/v1/candidate/attempts/start', ['exam_id' => $examId]);
        $resMaint->assertStatus(422);

        // ۳. داوطلب با کامپیوتری که وضعیتش online است اما داوطلب دیگری روی آن Attempt فعال (disconnected) دارد
        $compBusy = $this->createComputerRecord($labId, ['computer_code' => 'PC-BSY', 'status' => 'online']);
        $otherCandId = $this->createCandidateRecord(['candidate_code' => 'OTH-BSY']);
        $otherAssignId = $this->createAssignmentRecord($otherCandId, $otherExamId, $shiftId, $labId, $compBusy);
        $this->createAttemptRecord($otherCandId, $otherExamId, $otherAssignId, $otherSnapshotId, $compBusy, [
            'status'            => 'disconnected',
            'connection_status' => 'disconnected',
        ]);

        $candBusyId = $this->createCandidateRecord(['candidate_code' => 'REJ-BSY']);
        $this->createAssignmentRecord($candBusyId, $examId, $shiftId, $labId, $compBusy);

        $this->authenticateAsCandidate(Candidate::findOrFail($candBusyId));
        $resBusy = $this->postJson('/api/v1/candidate/attempts/start', ['exam_id' => $examId]);
        $resBusy->assertStatus(422);
    }

    /**
     * سناریوی ۱۱c: جریان کامل (End-to-End) اجرای AutoAssign (confirmed=true) و سپس اجرای AttemptController::start
     * توسط داوطلب تخصیص‌یافته و محافظت از کامپیوتر در برابر تخصیص مجدد.
     */
    public function test_11c_end_to_end_auto_assign_then_attempt_start_transitions_and_protects_computer(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $this->createExamSnapshotRecord($examId);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-E2E-01', 'status' => 'online']);
        $cand1Id = $this->createCandidateRecord(['candidate_code' => 'E2E-101']);
        $assign1 = $this->createAssignmentRecord($cand1Id, $examId, $shiftId, $labId, null);

        // گام ۱: تخصیص خودکار توسط ادمین
        $this->authenticateAsAdmin($admin);
        $assignRes = $this->postJson("/api/v1/admin/labs/shifts/{$shiftId}/auto-assign", ['confirmed' => true]);
        $assignRes->assertStatus(200)
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('data.summary.will_be_assigned', 1);

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assign1,
            'computer_id' => $comp1,
        ]);

        // گام ۲: شروع آزمون توسط داوطلب روی کامپیوتر تخصیص‌یافته
        $this->authenticateAsCandidate(Candidate::findOrFail($cand1Id));
        $startRes = $this->postJson('/api/v1/candidate/attempts/start', ['exam_id' => $examId]);
        $startRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.computer_id', $comp1)
            ->assertJsonPath('data.is_resume', false);

        $this->assertSame('exam', DB::table('computers')->where('id', $comp1)->value('status'));
    }

    /**
     * سناریوی ۱۱e: حفظ کامل قابلیت Resume در AttemptController::start:
     * ۱. وقتی داوطلب قبلاً آزمون را شروع کرده و وضعیت کامپیوترش exam است (و یا Attempt در حالت disconnected است)،
     *    فراخوانی مجدد start همان Attempt را با کد 200 و is_resume=true برمی‌گرداند.
     * ۲. حتی پس از SessionTransfer به کامپیوتر دیگر (که باعث تفاوت attempts.computer_id و candidate_assignments.computer_id
     *    و تغییر وضعیت کامپیوتر مبدأ به maintenance می‌شود)، فراخوانی start توسط داوطلب بدون خطای 422 همان Attempt را Resume می‌کند.
     */
    public function test_11e_attempt_start_preserves_resume_before_and_after_session_transfer(): void
    {
        $admin = $this->createAuthenticatedAdminUser();
        $labId = $this->createLabRecord();
        $examId = $this->createExamRecord(['created_by' => $admin->id]);
        $this->createExamSnapshotRecord($examId);
        $shiftId = $this->createShiftRecord($examId, $labId, ['capacity' => 5]);

        $comp1 = $this->createComputerRecord($labId, ['computer_code' => 'PC-RSM-01', 'status' => 'online']);
        $comp2 = $this->createComputerRecord($labId, ['computer_code' => 'PC-RSM-02', 'status' => 'online']);

        $candId = $this->createCandidateRecord(['candidate_code' => 'RSM-101']);
        $this->createAssignmentRecord($candId, $examId, $shiftId, $labId, $comp1);

        $candidate = Candidate::findOrFail($candId);
        $this->authenticateAsCandidate($candidate);

        // ۱. شروع اولیه آزمون (ایجاد Attempt جدید)
        $initialStart = $this->postJson('/api/v1/candidate/attempts/start', ['exam_id' => $examId]);
        $initialStart->assertStatus(200)
            ->assertJsonPath('data.is_resume', false);

        $attemptId = (int) $initialStart->json('data.attempt_id');
        $this->assertSame('exam', DB::table('computers')->where('id', $comp1)->value('status'));

        // ۲. فراخوانی مجدد start در حالی که وضعیت کامپیوتر exam است -> باید Resume شود (HTTP 200)
        $resumeSamePc = $this->postJson('/api/v1/candidate/attempts/start', ['exam_id' => $examId]);
        $resumeSamePc->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attempt_id', $attemptId)
            ->assertJsonPath('data.is_resume', true)
            ->assertJsonPath('data.computer_id', $comp1);

        // ۳. انتقال جلسه داوطلب از PC-RSM-01 به PC-RSM-02 و قرار دادن PC-RSM-01 در وضعیت maintenance
        $this->authenticateAsAdmin($admin);
        $transferRes = $this->postJson("/api/v1/admin/attempts/{$attemptId}/session-transfers", [
            'attempt_id'          => $attemptId,
            'to_computer_id'      => $comp2,
            'reason'              => 'Monitor flicker on PC-RSM-01',
            'source_Handling' => 'maintenance',
        ]);
        $transferRes->assertStatus(201);

        // شبیه‌سازی قطع ارتباط موقت داوطلب پس از انتقال (status = disconnected)
        DB::table('attempts')->where('id', $attemptId)->update([
            'status'            => 'disconnected',
            'connection_status' => 'disconnected',
            'disconnected_at'   => now(),
        ]);

        // ۴. فراخوانی مجدد start توسط داوطلب پس از انتقال جلسه (در حالی که کامپیوتر اولیه در candidate_assignments هنوز comp1 و maintenance است)
        $this->authenticateAsCandidate($candidate);
        $resumeAfterTransfer = $this->postJson('/api/v1/candidate/attempts/start', ['exam_id' => $examId]);
        $resumeAfterTransfer->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attempt_id', $attemptId)
            ->assertJsonPath('data.is_resume', true)
            ->assertJsonPath('data.computer_id', $comp2)
            ->assertJsonPath('data.status', 'disconnected');

        // اطمینان از اینکه هیچ Attempt دومی ساخته نشده است
        $this->assertSame(1, DB::table('attempts')->where('candidate_id', $candId)->where('exam_id', $examId)->count());
    }

    // =========================================================================
    // توابع کمکی احراز هویت و ساخت داده (Schema-Aware Fixture Builders)
    // =========================================================================

    /**
     * احراز هویت کاربر ادمین روی گارد واقعی 'admin' مطابق Middleware مسیرهای ادمین (auth:admin).
     */
    private function authenticateAsAdmin(User $admin): void
    {
        Sanctum::actingAs($admin, ['*'], 'admin');
        $this->actingAs($admin, 'admin');
    }

    /**
     * احراز هویت داوطلب روی گارد واقعی 'candidate' مطابق Middleware مسیرهای داوطلب (auth:candidate).
     */
    private function authenticateAsCandidate(Candidate $candidate): void
    {
        Sanctum::actingAs($candidate, ['*'], 'candidate');
        $this->actingAs($candidate, 'candidate');
    }

    /**
     * ایجاد کاربر ادمین با نقش واقعی 'Super Admin' (مطابق منطق User::hasPermission و EnsureHasRole).
     */
    private function createAuthenticatedAdminUser(): User
    {
        Gate::before(static fn () => true);

        $roleId = null;
        if (Schema::hasTable('roles')) {
            $roleName = 'Super Admin';
            $existingRole = DB::table('roles')->where('name', $roleName)->first();
            if ($existingRole) {
                $roleId = (int) $existingRole->id;
            } else {
                $roleId = $this->insertRowWithSchemaDefaults('roles', [
                    'name'           => $roleName,
                    'is_system_role' => 1,
                ]);
            }
        }

        $unique = uniqid('usr_', true);
        $userOverrides = [
            'name'      => 'Super Admin ' . $unique,
            'username'  => 'admin_' . str_replace('.', '', $unique),
            'email'     => 'admin_' . str_replace('.', '', $unique) . '@example.test',
            'password'  => Hash::make('Password123!'),
            'is_active' => 1,
        ];

        if ($roleId !== null && Schema::hasColumn('users', 'role_id')) {
            $userOverrides['role_id'] = $roleId;
        }

        $userId = $this->insertRowWithSchemaDefaults('users', $userOverrides);

        return User::findOrFail($userId);
    }

    /**
     * ایجاد رکورد کاربر واقعی برای استفاده در کلیدهای خارجی مانند exams.created_by.
     */
    private function createCreatorUserRecord(array $overrides = []): int
    {
        $unique = str_replace('.', '', uniqid('creator_', true));

        return $this->insertRowWithSchemaDefaults('users', array_merge([
            'name'      => 'Creator User ' . $unique,
            'username'  => 'user_' . $unique,
            'email'     => 'user_' . $unique . '@example.test',
            'password'  => Hash::make('Password123!'),
            'role_id'   => null,
            'is_active' => 1,
        ], $overrides));
    }

    /**
     * ایجاد رکورد مضمون (Subject) واقعی برای استفاده در کلید خارجی اجباری exams.subject_id.
     */
    private function createSubjectRecord(array $overrides = []): int
    {
        $unique = strtoupper(str_replace('.', '', uniqid('SUBJ_', true)));

        return $this->insertRowWithSchemaDefaults('subjects', array_merge([
            'name'       => 'Subject ' . $unique,
            'code'       => $unique,
            'faculty_id' => null,
            'is_active'  => 1,
            'created_by' => null,
        ], $overrides));
    }

    /**
     * ایجاد رکورد لابراتوار.
     */
    private function createLabRecord(array $overrides = []): int
    {
        return $this->insertRowWithSchemaDefaults('labs', array_merge([
            'name'     => 'Lab ' . uniqid(),
            'location' => 'Building A',
        ], $overrides));
    }

    /**
     * ایجاد رکورد کامپیوتر.
     */
    private function createComputerRecord(int $labId, array $overrides = []): int
    {
        $suffix = strtoupper(substr(uniqid(), -5));
        return $this->insertRowWithSchemaDefaults('computers', array_merge([
            'lab_id'        => $labId,
            'computer_code' => 'PC-' . $suffix,
            'ip_address'    => '10.10.' . random_int(1, 250) . '.' . random_int(2, 250),
            'hostname'      => 'host-' . strtolower($suffix),
            'status'        => 'online',
        ], $overrides));
    }

    /**
     * ایجاد رکورد آزمون با شناسه‌های واقعی برای subject_id و created_by.
     */
    private function createExamRecord(array $overrides = []): int
    {
        if (!array_key_exists('subject_id', $overrides) || $overrides['subject_id'] === null) {
            $overrides['subject_id'] = $this->createSubjectRecord();
        }

        if (!array_key_exists('created_by', $overrides) || $overrides['created_by'] === null) {
            $overrides['created_by'] = $this->createCreatorUserRecord();
        }

        return $this->insertRowWithSchemaDefaults('exams', array_merge([
            'title'            => 'Exam ' . uniqid(),
            'duration_minutes' => 60,
            'total_marks'      => 100,
            'pass_marks'       => 50,
            'status'           => 'published',
        ], $overrides));
    }

    /**
     * ایجاد رکورد Snapshot آزمون.
     */
    private function createExamSnapshotRecord(int $examId, array $overrides = []): int
    {
        return $this->insertRowWithSchemaDefaults('exam_snapshots', array_merge([
            'exam_id'      => $examId,
            'generated_at' => now(),
            'is_immutable' => 1,
        ], $overrides));
    }

    /**
     * ایجاد رکورد شیفت.
     */
    private function createShiftRecord(int $examId, int $labId, array $overrides = []): int
    {
        return $this->insertRowWithSchemaDefaults('shifts', array_merge([
            'exam_id'    => $examId,
            'lab_id'     => $labId,
            'name'       => 'Shift ' . uniqid(),
            'start_time' => now()->subMinutes(10),
            'end_time'   => now()->addMinutes(120),
            'capacity'   => 20,
        ], $overrides));
    }

    /**
     * ایجاد رکورد داوطلب با وضعیت پیش‌فرض معتبر در enum جدول candidates ('present').
     */
    private function createCandidateRecord(array $overrides = []): int
    {
        $suffix = strtoupper(substr(uniqid(), -6));
        return $this->insertRowWithSchemaDefaults('candidates', array_merge([
            'candidate_code' => 'CND-' . $suffix,
            'name'           => 'Candidate ' . $suffix,
            'username'       => 'cand_' . strtolower($suffix) . '_' . random_int(100, 999),
            'password'       => Hash::make('CandPass123!'),
            'national_id'    => (string) random_int(1000000000, 9999999999),
            'status'         => 'present',
        ], $overrides));
    }

    /**
     * ایجاد رکورد تخصیص داوطلب (CandidateAssignment).
     */
    private function createAssignmentRecord(
        int $candidateId,
        int $examId,
        int $shiftId,
        int $labId,
        ?int $computerId = null,
        array $overrides = []
    ): int {
        return $this->insertRowWithSchemaDefaults('candidate_assignments', array_merge([
            'candidate_id' => $candidateId,
            'exam_id'      => $examId,
            'shift_id'     => $shiftId,
            'lab_id'       => $labId,
            'computer_id'  => $computerId,
            'assigned_by'  => null,
            'assigned_at'  => $computerId !== null ? now() : null,
        ], $overrides));
    }

    /**
     * ایجاد رکورد تلاش آزمون (Attempt).
     */
    private function createAttemptRecord(
        int $candidateId,
        int $examId,
        int $assignmentId,
        int $snapshotId,
        int $computerId,
        array $overrides = []
    ): int {
        return $this->insertRowWithSchemaDefaults('attempts', array_merge([
            'candidate_id'            => $candidateId,
            'exam_id'                 => $examId,
            'candidate_assignment_id' => $assignmentId,
            'exam_snapshot_id'        => $snapshotId,
            'computer_id'             => $computerId,
            'ip_address'              => '127.0.0.1',
            'status'                  => 'in_progress',
            'connection_status'       => 'connected',
            'session_token'           => bin2hex(random_bytes(16)),
            'started_at'              => now()->subMinutes(5),
            'expires_at'              => now()->addMinutes(55),
            'last_activity_at'        => now(),
        ], $overrides));
    }

    /**
     * درج رکورد در جدول با بررسی دقیق ستون‌های اجباری Schema دیتابیس تست.
     * هیچ مقدار ثابت یا فرضی برای کلیدهای خارجی قرار داده نمی‌شود.
     *
     * @param string               $table
     * @param array<string, mixed> $overrides
     */
    private function insertRowWithSchemaDefaults(string $table, array $overrides): int
    {
        $columns = DB::select("SHOW COLUMNS FROM `{$table}`");
        $row = [];
        $now = now()->format('Y-m-d H:i:s');

        $fkLikeColumns = [
            'created_by',
            'approved_by',
            'assigned_by',
            'transferred_by',
            'performed_by',
            'recalculated_by',
            'initiated_by',
            'uploaded_by',
            'reviewed_by',
            'changed_by',
            'updated_by',
            'granted_by',
            'responsible_user_id',
            'notifiable_user_id',
        ];

        foreach ($columns as $col) {
            $colName = (string) $col->Field;
            $extra = strtolower((string) ($col->Extra ?? ''));
            if (str_contains($extra, 'auto_increment')) {
                continue;
            }

            if (array_key_exists($colName, $overrides)) {
                $val = $overrides[$colName];
                if ($val instanceof \DateTimeInterface) {
                    $val = $val->format('Y-m-d H:i:s');
                }
                $row[$colName] = $val;
                continue;
            }

            if ($colName === 'created_at' || $colName === 'updated_at') {
                $row[$colName] = $now;
                continue;
            }

            $isNullable = strtoupper((string) $col->Null) === 'YES';
            $default = $col->Default;

            if ($default !== null || $isNullable) {
                continue;
            }

            // ممنوعیت کامل قرار دادن مقدار فرضی برای هرگونه کلید خارجی اجباری
            if (str_ends_with($colName, '_id') || in_array($colName, $fkLikeColumns, true)) {
                throw new InvalidArgumentException(
                    "Missing required foreign key column '{$table}.{$colName}'. Explicit valid ID is required."
                );
            }

            $type = strtolower((string) $col->Type);
            if (str_starts_with($type, 'enum(')) {
                if (preg_match("/^enum\('([^']+)'/", (string) $col->Type, $matches)) {
                    $row[$colName] = $matches[1];
                }
            } elseif (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
                $row[$colName] = 1;
            } elseif (str_contains($type, 'json')) {
                $row[$colName] = json_encode([]);
            } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
                $row[$colName] = $now;
            } else {
                $row[$colName] = $colName . '_' . uniqid();
            }
        }

        return (int) DB::table($table)->insertGetId($row);
    }
}