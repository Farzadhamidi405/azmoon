<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\Incident;
use App\Models\Lab;
use App\Models\Question;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های Audit برای ۲۳ عملیات CRUD ادمین (Step 14.6.5d).
 *
 * چه چیزی را می‌سنجد:
 *  - هر عملیات موفق دقیقاً یک ردیف audit می‌نویسد (با شمارش کل ردیف‌ها)؛
 *  - مقادیر حساس هرگز در old_value / new_value نمی‌آیند (فقط نام فیلد در changed_fields)؛
 *  - اگر نوشتن audit شکست بخورد، کل عملیات اصلی Rollback می‌شود؛
 *  - مسیرهای 403 و 422 هیچ audit ننویسند.
 *
 * چرا: Audit باید با عملیات اصلی هم‌تراکنش باشد و اطلاعات حساس نشت نکند.
 * ایمنی: فقط روی دیتابیس azmoon_test اجرا می‌شود؛ در غیر این صورت قبل از هر
 * دسترسی به DB متوقف می‌شود.
 */
class CrudAuditTest extends TestCase
{
    use RefreshDatabase;

    /** پیشوند مسیر API ادمین */
    private const BASE = '/api/v1/admin';

    /** واژه‌های ممنوع در کلیدهای old_value/new_value (بدون حساسیت به حروف) */
    private const SENSITIVE_WORDS = ['password', 'token', 'secret', 'national_id'];

    /** کاربر ادمین اصلی تست (Super Admin) */
    private User $admin;

    /**
     * آماده‌سازی: گارد محیط قبل از بوت برنامه (چون RefreshDatabase هنگام بوت
     * روی DB مهاجرت اجرا می‌کند)، سپس گارد دوم پس از بوت، سپس ساخت ادمین.
     */
    protected function setUp(): void
    {
        // گارد اول: متغیرهای محیطی باید صریحاً روی دیتابیس تست تنظیم شده باشند
        if ($this->readEnvValue('DB_DATABASE') !== 'azmoon_test') {
            $this->fail('SAFETY: DB_DATABASE env must be exactly azmoon_test before boot.');
        }
        if ($this->readEnvValue('APP_ENV') !== 'testing') {
            $this->fail('SAFETY: APP_ENV env must be testing before boot.');
        }
        if (file_exists(dirname(__DIR__, 4) . '/bootstrap/cache/config.php')) {
            $this->fail('SAFETY: bootstrap/cache/config.php exists (cached config can bypass env).');
        }

        parent::setUp();

        // گارد دوم: اتصال واقعی بعد از بوت
        if (config('database.default') !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'azmoon_test') {
            $this->fail('SAFETY: active connection is not mysql/azmoon_test.');
        }

        // ادمین Super Admin (همیشه مجاز در hasPermission)
        $role = Role::create(['name' => 'Super Admin', 'is_system_role' => true]);
        $this->admin = User::create([
            'name' => 'Audit Admin',
            'username' => 'audit_admin',
            'password' => bcrypt('Audit-Admin-Pass-1'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, [], 'admin');
    }

    // ------------------------------------------------------------------
    // Data Providers
    // ------------------------------------------------------------------

    /**
     * فهرست ۲۳ عملیات: [کلید، action مورد انتظار، module، پسوند target_type]
     */
    public static function operationProvider(): array
    {
        return [
            'candidate_store' => ['candidate_store', 'Candidate:created', 'candidates', 'Candidate'],
            'candidate_update' => ['candidate_update', 'Candidate:updated', 'candidates', 'Candidate'],
            'candidate_destroy' => ['candidate_destroy', 'Candidate:deleted', 'candidates', 'Candidate'],
            'candidate_assign' => ['candidate_assign', 'Candidate:assigned', 'candidates', 'Candidate'],
            'candidate_bulk' => ['candidate_bulk', 'Candidate:bulk_generated', 'candidates', 'Candidate'],
            'computer_store' => ['computer_store', 'Computer:created', 'computers', 'Computer'],
            'computer_update' => ['computer_update', 'Computer:updated', 'computers', 'Computer'],
            'computer_destroy' => ['computer_destroy', 'Computer:deleted', 'computers', 'Computer'],
            'computer_status' => ['computer_status', 'Computer:status_changed', 'computers', 'Computer'],
            'exam_store' => ['exam_store', 'Exam:created', 'exams', 'Exam'],
            'exam_update' => ['exam_update', 'Exam:updated', 'exams', 'Exam'],
            'exam_destroy' => ['exam_destroy', 'Exam:deleted', 'exams', 'Exam'],
            'exam_status' => ['exam_status', 'Exam:status_changed', 'exams', 'Exam'],
            'question_store' => ['question_store', 'Question:created', 'questions', 'Question'],
            'question_update' => ['question_update', 'Question:updated', 'questions', 'Question'],
            'question_destroy' => ['question_destroy', 'Question:deleted', 'questions', 'Question'],
            'question_status' => ['question_status', 'Question:status_changed', 'questions', 'Question'],
            'shift_store' => ['shift_store', 'Shift:created', 'shifts', 'Shift'],
            'shift_update' => ['shift_update', 'Shift:updated', 'shifts', 'Shift'],
            'shift_destroy' => ['shift_destroy', 'Shift:deleted', 'shifts', 'Shift'],
            'incident_store' => ['incident_store', 'Incident:created', 'incidents', 'Incident'],
            'incident_update' => ['incident_update', 'Incident:updated', 'incidents', 'Incident'],
            'incident_resolve' => ['incident_resolve', 'Incident:resolved', 'incidents', 'Incident'],
        ];
    }

    /**
     * عملیات‌هایی که باید برای نقش بدون مجوز 403 بدهند: [کلید، نام نقش]
     * (نقش بدون Permission برای ماژول‌های Candidate/Computer/Shift، و Reviewer برای Incident)
     */
    public static function forbiddenProvider(): array
    {
        return [
            'candidate_store' => ['candidate_store', 'Question Manager'],
            'candidate_update' => ['candidate_update', 'Question Manager'],
            'candidate_assign' => ['candidate_assign', 'Question Manager'],
            'candidate_bulk' => ['candidate_bulk', 'Question Manager'],
            'computer_store' => ['computer_store', 'Question Manager'],
            'computer_update' => ['computer_update', 'Question Manager'],
            'computer_destroy' => ['computer_destroy', 'Question Manager'],
            'computer_status' => ['computer_status', 'Question Manager'],
            'shift_store' => ['shift_store', 'Question Manager'],
            'shift_update' => ['shift_update', 'Question Manager'],
            'shift_destroy' => ['shift_destroy', 'Question Manager'],
            'incident_store' => ['incident_store', 'Reviewer'],
            'incident_update' => ['incident_update', 'Reviewer'],
            'incident_resolve' => ['incident_resolve', 'Reviewer'],
        ];
    }

    // ------------------------------------------------------------------
    // تست ۱: هر ۲۳ عملیات دقیقاً یک audit تمیز می‌نویسند
    // ------------------------------------------------------------------

    /**
     * برای هر عملیات: پاسخ موفق، دقیقاً یک ردیف audit جدید، فیلدهای عمومی درست،
     * و نبودن هر مقدار/کلید حساس (از جمله رمزهای موجود در پاسخ API).
     */
    #[Test]
    #[DataProvider('operationProvider')]
    public function each_operation_writes_exactly_one_clean_audit(string $op, string $action, string $module, string $targetSuffix): void
    {
        $prep = $this->prepare($op);
        $before = AuditLog::count();

        $res = $this->sendOp($prep);
        $res->assertSuccessful();

        // دقیقاً یک ردیف جدید و با action درست
        $row = $this->expectOneAudit($before, $action);

        // شناسه هدف: برای ایجاد از روی رکورد ساخته‌شده، برای بقیه از fixture
        $targetId = $prep['target_id'];
        if ($targetId === null && $prep['finder'] !== null) {
            $created = ($prep['finder'])();
            $this->assertNotNull($created, $op . ': created record not found');
            $targetId = (int) $created->id;
        }
        $this->assertCommon($row, $module, $targetSuffix, $targetId);

        // رمزهای تولیدشده در پاسخ API نباید در audit باشند
        $secrets = [];
        $this->collectByKey($res->json(), ['plain_password', 'password'], $secrets);
        if ($op === 'candidate_store' || $op === 'candidate_bulk') {
            $this->assertNotEmpty($secrets, $op . ': no plain_password found in response; sensitive check would be meaningless');
        }
        $this->assertAuditClean($row, array_merge($prep['forbidden'], $secrets));

        // حذف: رکورد واقعاً حذف شده و old_value قبل از حذف ثبت شده باشد
        if (str_ends_with($op, '_destroy')) {
            $modelClass = $this->modelClassFor($op);
            $this->assertNull($modelClass::find($targetId), $op . ': record still visible after delete');
            $this->assertNotNull($this->decode($row, 'old_value'), $op . ': old_value must be captured before delete');
        }
    }

    // ------------------------------------------------------------------
    // تست ۲: Rollback برای هر ۲۳ عملیات
    // ------------------------------------------------------------------

    /**
     * با شکست اجباری نوشتن AuditLog (رویداد creating)، عملیات اصلی باید کاملاً
     * Rollback شود: اثرانگشت جداول مرتبط قبل و بعد باید یکسان باشد.
     * (روش رویداد مدل به پیاده‌سازی داخلی سرویس وابسته نیست.)
     */
    #[Test]
    #[DataProvider('operationProvider')]
    public function failed_audit_rolls_back_the_main_operation(string $op, string $action, string $module, string $targetSuffix): void
    {
        $prep = $this->prepare($op);
        $fingerprintBefore = $this->fingerprint();

        AuditLog::creating(function () {
            throw new \RuntimeException('forced audit failure (test)');
        });

        try {
            $res = $this->sendOp($prep);
        } finally {
            // حذف شنونده تا تست‌های بعدی تحت تأثیر قرار نگیرند
            AuditLog::flushEventListeners();
        }

        $this->assertSame(500, $res->getStatusCode(), $op . ': audit failure must surface as 500');
        $this->assertSame($fingerprintBefore, $this->fingerprint(), $op . ': main operation was NOT rolled back');
    }

    // ------------------------------------------------------------------
    // تست ۳: مسیرهای 403 و 422 هیچ audit نمی‌نویسند
    // ------------------------------------------------------------------

    /**
     * کاربر بدون مجوز باید 403 بگیرد و هیچ audit و هیچ تغییری ثبت نشود.
     */
    #[Test]
    #[DataProvider('forbiddenProvider')]
    public function forbidden_requests_write_no_audit(string $op, string $roleName): void
    {
        $prep = $this->prepare($op);               // fixture با ادمین ساخته می‌شود
        $restricted = $this->makeUser($roleName);  // سپس به کاربر محدود سوییچ می‌کنیم
        Sanctum::actingAs($restricted, [], 'admin');

        $before = AuditLog::count();
        $fingerprint = $this->fingerprint();

        $res = $this->sendOp($prep);

        $this->assertSame(403, $res->getStatusCode(), $op . ': expected 403, body=' . $res->getContent());
        $this->assertSame($before, AuditLog::count(), $op . ': audit written on 403');
        $this->assertSame($fingerprint, $this->fingerprint(), $op . ': data changed on 403');
    }

    /**
     * خطاهای اعتبارسنجی/قانون کسب‌وکار (422) نباید audit بنویسند.
     */
    #[Test]
    public function validation_and_business_rule_failures_write_no_audit(): void
    {
        $scenarios = [
            'candidate_store_missing_name' => function () {
                $p = $this->prepare('candidate_store');
                $p['payload'] = ['national_id' => '1234509876'];
                return $this->sendOp($p);
            },
            'candidate_bulk_zero_count' => function () {
                $p = $this->prepare('candidate_bulk');
                $p['payload'] = ['count' => 0, 'prefix' => 'AUD'];
                return $this->sendOp($p);
            },
            'computer_status_exam_rejected' => function () {
                $p = $this->prepare('computer_status');
                $p['payload'] = ['status' => 'exam'];
                return $this->sendOp($p);
            },
            'computer_destroy_in_exam' => function () {
                $c = Computer::factory()->create(['status' => 'exam']);
                return $this->json('DELETE', self::BASE . '/computers/' . $c->id, []);
            },
            'exam_status_invalid_transition' => function () {
                $p = $this->prepare('exam_status');
                $p['payload'] = ['status' => 'published'];
                return $this->sendOp($p);
            },
            'exam_store_pass_greater_than_total' => function () {
                $p = $this->prepare('exam_store');
                $p['payload']['pass_marks'] = 200;
                $p['payload']['total_marks'] = 100;
                return $this->sendOp($p);
            },
            'shift_store_end_not_after_start' => function () {
                $p = $this->prepare('shift_store');
                $p['payload']['end_time'] = $p['payload']['start_time'];
                return $this->sendOp($p);
            },
            'incident_store_without_target' => function () {
                $p = $this->prepare('incident_store');
                unset($p['payload']['candidate_id']);
                return $this->sendOp($p);
            },
        ];

        foreach ($scenarios as $name => $run) {
            $before = AuditLog::count();
            $res = $run();
            $this->assertSame(422, $res->getStatusCode(), $name . ': expected 422, body=' . $res->getContent());
            $this->assertSame($before, AuditLog::count(), $name . ': audit written on 422');
        }
    }

    /**
     * حل‌کردن دوباره‌ی یک Incident حل‌شده باید 422 بدهد و audit دوم ننویسد.
     */
    #[Test]
    public function resolving_an_already_resolved_incident_writes_no_second_audit(): void
    {
        $prep = $this->prepare('incident_resolve');
        $this->sendOp($prep)->assertSuccessful();
        $afterFirst = AuditLog::count();

        $res = $this->sendOp($prep);

        $this->assertSame(422, $res->getStatusCode(), 'second resolve must be 422, body=' . $res->getContent());
        $this->assertSame($afterFirst, AuditLog::count());
        $this->assertSame(1, AuditLog::where('action', 'Incident:resolved')->count());
    }

    // ------------------------------------------------------------------
    // تست ۴: جزئیات محتوای audit هر ماژول
    // ------------------------------------------------------------------

    /** ایجاد داوطلب: old_value خالی، نام و وضعیت ثبت و national_id حذف شده باشد. */
    #[Test]
    public function candidate_store_audit_has_new_values_without_sensitive_data(): void
    {
        [$prep, $res, $before] = $this->runOp('candidate_store');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Candidate:created');

        $new = $this->decode($row, 'new_value');
        $this->assertNotNull($new);
        $this->assertSame('Audit Candidate Store', $new['name'] ?? null);
        $this->assertSame('absent', $new['status'] ?? null);
        $this->assertNull($this->decode($row, 'old_value'));
        $this->assertArrayNotHasKey('national_id', $new);
    }

    /** ویرایش داوطلب: فقط نام فیلد حساس در changed_fields بیاید، نه مقدار آن. */
    #[Test]
    public function candidate_update_audit_lists_sensitive_field_name_only(): void
    {
        [$prep, $res, $before] = $this->runOp('candidate_update');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Candidate:updated');

        $changed = $this->changedFields($row);
        $this->assertContains('name', $changed);
        $this->assertContains('national_id', $changed);
        $new = $this->decode($row, 'new_value');
        $this->assertSame('Audit Candidate Renamed', $new['name'] ?? null);
        $this->assertAuditClean($row, ['1111222233', '4444555566']);
    }

    /** حذف نرم داوطلب: رکورد soft-deleted و اطلاعات قبل از حذف ثبت شده باشد. */
    #[Test]
    public function candidate_destroy_audit_captures_data_before_soft_delete(): void
    {
        [$prep, $res, $before] = $this->runOp('candidate_destroy');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Candidate:deleted');

        $this->assertSoftDeleted('candidates', ['id' => $prep['target_id']]);
        $old = $this->decode($row, 'old_value');
        $this->assertNotNull($old);
        $this->assertSame($prep['ctx']['model']->name, $old['name'] ?? null);
    }

    /** تخصیص جدید: شناسه‌های shift/lab/computer ثبت شود و was_update=false باشد. */
    #[Test]
    public function candidate_assign_audit_records_new_assignment(): void
    {
        [$prep, $res, $before] = $this->runOp('candidate_assign');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Candidate:assigned');

        $new = $this->decode($row, 'new_value');
        $this->assertNotNull($new);
        $this->assertSame($prep['ctx']['shift']->id, (int) ($new['shift_id'] ?? 0));
        $this->assertSame($prep['ctx']['shift']->lab_id, (int) ($new['lab_id'] ?? 0));
        $this->assertSame($prep['ctx']['computer']->id, (int) ($new['computer_id'] ?? 0));
        $this->assertFalse((bool) ($new['was_update'] ?? true));
        $this->assertSame(1, CandidateAssignment::count());
    }

    /** تخصیص مجدد: old_value مقدار قبلی را نگه دارد و was_update=true باشد. */
    #[Test]
    public function candidate_reassign_audit_records_previous_values(): void
    {
        $prep = $this->prepare('candidate_assign');
        $this->sendOp($prep)->assertSuccessful();

        $second = Computer::factory()->create(['lab_id' => $prep['ctx']['shift']->lab_id]);
        $prep['payload']['computer_id'] = $second->id;
        $before = AuditLog::count();

        $this->sendOp($prep)->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Candidate:assigned', 2);

        $old = $this->decode($row, 'old_value');
        $new = $this->decode($row, 'new_value');
        $this->assertNotNull($old);
        $this->assertSame($prep['ctx']['computer']->id, (int) ($old['computer_id'] ?? 0));
        $this->assertSame($second->id, (int) ($new['computer_id'] ?? 0));
        $this->assertTrue((bool) ($new['was_update'] ?? false));
        $this->assertContains('computer_id', $this->changedFields($row));
    }

    /** ایجاد گروهی: یک audit با تعداد/پیشوند/شناسه‌ها و بدون هیچ رمزی. */
    #[Test]
    public function candidate_bulk_generate_writes_single_audit_without_passwords(): void
    {
        [$prep, $res, $before] = $this->runOp('candidate_bulk');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Candidate:bulk_generated');

        $new = $this->decode($row, 'new_value');
        $this->assertNotNull($new);
        $this->assertSame(3, (int) ($new['count'] ?? 0));
        $this->assertCount(3, $new['candidate_ids'] ?? []);

        $actualIds = Candidate::pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $loggedIds = collect($new['candidate_ids'])->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame($actualIds, $loggedIds);
        $this->assertNull($row->target_id);
    }

    /** ویرایش کامپیوتر: نام فیلد تغییریافته در changed_fields باشد. */
    #[Test]
    public function computer_update_audit_lists_changed_field(): void
    {
        [$prep, $res, $before] = $this->runOp('computer_update');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Computer:updated');

        $this->assertContains('ip_address', $this->changedFields($row));
    }

    /** حذف سخت کامپیوتر: اطلاعات قبل از حذف (کد کامپیوتر) ثبت شده باشد. */
    #[Test]
    public function computer_destroy_audit_captures_data_before_hard_delete(): void
    {
        [$prep, $res, $before] = $this->runOp('computer_destroy');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Computer:deleted');

        $this->assertDatabaseMissing('computers', ['id' => $prep['target_id']]);
        $old = $this->decode($row, 'old_value');
        $this->assertSame($prep['ctx']['model']->computer_code, $old['computer_code'] ?? null);
    }

    /** تغییر وضعیت کامپیوتر: وضعیت قبلی و جدید ثبت شود. */
    #[Test]
    public function computer_status_audit_records_old_and_new_status(): void
    {
        [$prep, $res, $before] = $this->runOp('computer_status');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Computer:status_changed');

        $old = $this->decode($row, 'old_value');
        $new = $this->decode($row, 'new_value');
        $this->assertSame('online', $old['status'] ?? null);
        $this->assertSame('offline', $new['status'] ?? null);
    }

    /** تغییر وضعیت آزمون: draft به under_review. */
    #[Test]
    public function exam_status_audit_records_old_and_new_status(): void
    {
        [$prep, $res, $before] = $this->runOp('exam_status');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Exam:status_changed');

        $old = $this->decode($row, 'old_value');
        $new = $this->decode($row, 'new_value');
        $this->assertSame('draft', $old['status'] ?? null);
        $this->assertSame('under_review', $new['status'] ?? null);
    }

    /** ویرایش آزمون: نام فیلد title در changed_fields باشد. */
    #[Test]
    public function exam_update_audit_lists_changed_field(): void
    {
        [$prep, $res, $before] = $this->runOp('exam_update');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Exam:updated');

        $this->assertContains('title', $this->changedFields($row));
    }

    /** سوال: فقط متادیتا ثبت شود (بدون متن سوال و گزینه‌ها). */
    #[Test]
    public function question_store_audit_has_metadata_only(): void
    {
        [$prep, $res, $before] = $this->runOp('question_store');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Question:created');

        $new = $this->decode($row, 'new_value');
        $this->assertNotNull($new);
        $this->assertSame('single', $new['question_type'] ?? null);
        $this->assertSame(1, (int) ($new['version'] ?? 0));
        $this->assertSame(2, (int) ($new['options_count'] ?? 0));
        $this->assertAuditClean($row, $prep['forbidden']);
    }

    /** ویرایش سوال: یک audit برای کل عملیات، نسخه ۲، بدون متن سوال. */
    #[Test]
    public function question_update_writes_single_audit_with_new_version(): void
    {
        [$prep, $res, $before] = $this->runOp('question_update');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Question:updated');

        $new = $this->decode($row, 'new_value');
        $this->assertSame(2, (int) ($new['version'] ?? 0));
        $this->assertContains('question_text', $this->changedFields($row));
        $this->assertSame(2, $prep['ctx']['model']->versions()->count());
        $this->assertAuditClean($row, $prep['forbidden']);
    }

    /** حل Incident: وضعیت و resolved_at در changed_fields و وضعیت resolved در new_value. */
    #[Test]
    public function incident_resolve_audit_records_status_and_resolved_at(): void
    {
        [$prep, $res, $before] = $this->runOp('incident_resolve');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Incident:resolved');

        $new = $this->decode($row, 'new_value');
        $this->assertSame('resolved', $new['status'] ?? null);
        $changed = $this->changedFields($row);
        $this->assertContains('status', $changed);
        $this->assertContains('resolved_at', $changed);
    }

    /** حذف سخت شیفت: اطلاعات قبل از حذف ثبت شود. */
    #[Test]
    public function shift_destroy_audit_captures_data_before_hard_delete(): void
    {
        [$prep, $res, $before] = $this->runOp('shift_destroy');
        $res->assertSuccessful();
        $row = $this->expectOneAudit($before, 'Shift:deleted');

        $this->assertDatabaseMissing('shifts', ['id' => $prep['target_id']]);
        $old = $this->decode($row, 'old_value');
        $this->assertSame($prep['ctx']['model']->name, $old['name'] ?? null);
    }

    // ------------------------------------------------------------------
    // تست ۵: سرویس مقادیر حساس را بازگشتی و بدون حساسیت به حروف حذف می‌کند
    // ------------------------------------------------------------------

    /**
     * فراخوانی مستقیم record() با کلیدهای حساس (از جمله تو در تو و با حروف بزرگ)؛
     * مقادیر باید کاملاً حذف شوند، pass_marks/passage_id باقی بمانند و نام فیلد
     * حساس فقط در changed_fields بیاید.
     */
    #[Test]
    public function service_removes_sensitive_values_recursively(): void
    {
        $before = AuditLog::count();

        app(AuditLogService::class)->record(
            'candidates',
            'Test:sanitize',
            'Candidate',
            null,
            ['name' => 'a', 'PASSWORD' => 'LEAK_ONE', 'nested' => ['Remember_Token' => 'LEAK_TWO', 'ok' => 1]],
            ['name' => 'b', 'national_id' => 'LEAK_THREE', 'qr_token' => 'LEAK_FOUR', 'session_token' => 'LEAK_FIVE', 'secret_key' => 'LEAK_SIX', 'pass_marks' => 50, 'passage_id' => 7],
            ['name', 'national_id']
        );

        $row = $this->expectOneAudit($before, 'Test:sanitize');
        $this->assertAuditClean($row, ['LEAK_ONE', 'LEAK_TWO', 'LEAK_THREE', 'LEAK_FOUR', 'LEAK_FIVE', 'LEAK_SIX']);

        $new = $this->decode($row, 'new_value');
        $this->assertSame(50, (int) ($new['pass_marks'] ?? 0), 'pass_marks must NOT be treated as sensitive');
        $this->assertSame(7, (int) ($new['passage_id'] ?? 0), 'passage_id must NOT be treated as sensitive');
        $this->assertContains('national_id', $this->changedFields($row));
    }

    // ------------------------------------------------------------------
    // ابزارهای کمکی: محیط
    // ------------------------------------------------------------------

    /** خواندن مقدار محیطی از getenv / $_ENV / $_SERVER (قبل از بوت لاراول). */
    private function readEnvValue(string $key): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_ENV[$key] ?? ($_SERVER[$key] ?? '');
        }
        return (string) $v;
    }

    // ------------------------------------------------------------------
    // ابزارهای کمکی: کاربران و ارسال درخواست
    // ------------------------------------------------------------------

    /** ساخت کاربر فعال با نقش داده‌شده (نقش در صورت نبود ساخته می‌شود). */
    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['is_system_role' => true]);
        return User::create([
            'name' => 'User ' . $roleName,
            'username' => 'u_' . substr(md5($roleName . microtime(true)), 0, 10),
            'password' => bcrypt('Audit-User-Pass-1'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /** ارسال درخواست آماده‌شده با متد/مسیر/بدنه‌ی داخل prep. */
    private function sendOp(array $prep): \Illuminate\Testing\TestResponse
    {
        return $this->json($prep['method'], $prep['uri'], $prep['payload']);
    }

    /** آماده‌سازی، گرفتن شمارش audit، ارسال؛ خروجی: [prep, response, شمارش قبل]. */
    private function runOp(string $op): array
    {
        $prep = $this->prepare($op);
        $before = AuditLog::count();
        $res = $this->sendOp($prep);
        return [$prep, $res, $before];
    }

    /** ساخت ساختار استاندارد prep. */
    private function prep(string $method, string $uri, array $payload, ?int $targetId, ?callable $finder, array $forbidden = [], array $ctx = []): array
    {
        return [
            'method' => $method,
            'uri' => self::BASE . $uri,
            'payload' => $payload,
            'target_id' => $targetId,
            'finder' => $finder,
            'forbidden' => $forbidden,
            'ctx' => $ctx,
        ];
    }

    // ------------------------------------------------------------------
    // ابزارهای کمکی: fixture هر عملیات
    // ------------------------------------------------------------------

    /**
     * ساخت fixture و درخواست هر یک از ۲۳ عملیات.
     * هیچ‌کدام از fixtureها خودشان audit نمی‌نویسند (به‌جز ساخت سوال از طریق API
     * که قبل از شمارش پایه انجام می‌شود).
     */
    private function prepare(string $op): array
    {
        switch ($op) {
            case 'candidate_store':
                $max = (int) Candidate::withTrashed()->max('id');
                return $this->prep('POST', '/candidates',
                    ['name' => 'Audit Candidate Store', 'national_id' => '9876543210'],
                    null, fn () => Candidate::withTrashed()->where('id', '>', $max)->first(), ['9876543210']);

            case 'candidate_update':
                $c = Candidate::factory()->create(['national_id' => '1111222233']);
                return $this->prep('PUT', '/candidates/' . $c->id,
                    ['name' => 'Audit Candidate Renamed', 'national_id' => '4444555566'],
                    (int) $c->id, null, ['1111222233', '4444555566'], ['model' => $c]);

            case 'candidate_destroy':
                $c = Candidate::factory()->create(['national_id' => '7777888899']);
                return $this->prep('DELETE', '/candidates/' . $c->id, [],
                    (int) $c->id, null, ['7777888899'], ['model' => $c]);

            case 'candidate_assign':
                $shift = Shift::factory()->create();
                $computer = Computer::factory()->create(['lab_id' => $shift->lab_id]);
                $c = Candidate::factory()->create();
                return $this->prep('POST', '/candidates/' . $c->id . '/assign',
                    ['exam_id' => $shift->exam_id, 'shift_id' => $shift->id, 'lab_id' => $shift->lab_id, 'computer_id' => $computer->id],
                    (int) $c->id, null, [], ['shift' => $shift, 'computer' => $computer, 'candidate' => $c]);

            case 'candidate_bulk':
                return $this->prep('POST', '/candidates/bulk-generate',
                    ['count' => 3, 'prefix' => 'AUD'], null, null);

            case 'computer_store':
                $lab = Lab::factory()->create();
                $max = (int) Computer::max('id');
                return $this->prep('POST', '/computers',
                    ['lab_id' => $lab->id, 'computer_code' => 'AUD-PC-001', 'ip_address' => '10.20.30.40'],
                    null, fn () => Computer::where('id', '>', $max)->first());

            case 'computer_update':
                $c = Computer::factory()->create();
                return $this->prep('PUT', '/computers/' . $c->id,
                    ['lab_id' => $c->lab_id, 'computer_code' => $c->computer_code, 'ip_address' => '10.20.30.41'],
                    (int) $c->id, null, [], ['model' => $c]);

            case 'computer_destroy':
                $c = Computer::factory()->create();
                return $this->prep('DELETE', '/computers/' . $c->id, [],
                    (int) $c->id, null, [], ['model' => $c]);

            case 'computer_status':
                $c = Computer::factory()->create();
                return $this->prep('PATCH', '/computers/' . $c->id . '/status',
                    ['status' => 'offline'], (int) $c->id, null, [], ['model' => $c]);

            case 'exam_store':
                $subject = Subject::factory()->create();
                $max = (int) Exam::withTrashed()->max('id');
                return $this->prep('POST', '/exams',
                    ['subject_id' => $subject->id, 'title' => 'Audit Exam Store', 'duration_minutes' => 60, 'total_marks' => 100, 'pass_marks' => 50],
                    null, fn () => Exam::withTrashed()->where('id', '>', $max)->first());

            case 'exam_update':
                $e = Exam::factory()->create();
                return $this->prep('PUT', '/exams/' . $e->id,
                    ['subject_id' => $e->subject_id, 'title' => 'Audit Exam Renamed', 'duration_minutes' => 90, 'total_marks' => 100, 'pass_marks' => 40],
                    (int) $e->id, null, [], ['model' => $e]);

            case 'exam_destroy':
                $e = Exam::factory()->create();
                return $this->prep('DELETE', '/exams/' . $e->id, [],
                    (int) $e->id, null, [], ['model' => $e]);

            case 'exam_status':
                $e = Exam::factory()->create();
                return $this->prep('PATCH', '/exams/' . $e->id . '/status',
                    ['status' => 'under_review'], (int) $e->id, null, [], ['model' => $e]);

            case 'question_store':
                $subject = Subject::factory()->create();
                $max = (int) Question::withTrashed()->max('id');
                return $this->prep('POST', '/questions',
                    $this->questionPayload((int) $subject->id, 'AUDIT_QTEXT_STORE_X'),
                    null, fn () => Question::withTrashed()->where('id', '>', $max)->first(),
                    ['AUDIT_QTEXT_STORE_X', 'AUDIT_OPT_A', 'AUDIT_OPT_B']);

            case 'question_update':
                $q = $this->createQuestionViaApi('AUDIT_QTEXT_OLD_X');
                $payload = array_merge(
                    $this->questionPayload((int) $q->subject_id, 'AUDIT_QTEXT_NEW_X'),
                    ['change_reason' => 'audit test change']
                );
                return $this->prep('PUT', '/questions/' . $q->id, $payload,
                    (int) $q->id, null, ['AUDIT_QTEXT_OLD_X', 'AUDIT_QTEXT_NEW_X', 'AUDIT_OPT_A', 'AUDIT_OPT_B'], ['model' => $q]);

            case 'question_destroy':
                $q = $this->createQuestionViaApi('AUDIT_QTEXT_DEL_X');
                return $this->prep('DELETE', '/questions/' . $q->id, [],
                    (int) $q->id, null, ['AUDIT_QTEXT_DEL_X', 'AUDIT_OPT_A', 'AUDIT_OPT_B'], ['model' => $q]);

            case 'question_status':
                $q = $this->createQuestionViaApi('AUDIT_QTEXT_STAT_X');
                return $this->prep('PATCH', '/questions/' . $q->id . '/status',
                    ['status' => 'under_review'], (int) $q->id, null,
                    ['AUDIT_QTEXT_STAT_X', 'AUDIT_OPT_A', 'AUDIT_OPT_B'], ['model' => $q]);

            case 'shift_store':
                $exam = Exam::factory()->create();
                $lab = Lab::factory()->create();
                $max = (int) Shift::max('id');
                return $this->prep('POST', '/shifts',
                    $this->shiftPayload((int) $exam->id, (int) $lab->id, 'Audit Shift Store'),
                    null, fn () => Shift::where('id', '>', $max)->first());

            case 'shift_update':
                $s = Shift::factory()->create();
                return $this->prep('PUT', '/shifts/' . $s->id,
                    $this->shiftPayload((int) $s->exam_id, (int) $s->lab_id, 'Audit Shift Renamed'),
                    (int) $s->id, null, [], ['model' => $s]);

            case 'shift_destroy':
                $s = Shift::factory()->create();
                return $this->prep('DELETE', '/shifts/' . $s->id, [],
                    (int) $s->id, null, [], ['model' => $s]);

            case 'incident_store':
                $c = Candidate::factory()->create();
                $max = (int) Incident::max('id');
                return $this->prep('POST', '/incidents',
                    [
                        'candidate_id' => $c->id,
                        'problem_description' => 'Audit incident store description',
                        'responsible_user_id' => $this->admin->id,
                        'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                    ],
                    null, fn () => Incident::where('id', '>', $max)->first());

            case 'incident_update':
                $i = $this->makeIncident();
                return $this->prep('PUT', '/incidents/' . $i->id,
                    ['problem_description' => 'Audit incident updated description', 'action_taken' => 'Audit action taken text'],
                    (int) $i->id, null, [], ['model' => $i]);

            case 'incident_resolve':
                $i = $this->makeIncident();
                return $this->prep('PATCH', '/incidents/' . $i->id . '/resolve', [],
                    (int) $i->id, null, [], ['model' => $i]);
        }

        $this->fail('Unknown operation key: ' . $op);
    }

    /** ساخت Incident مستقیم (occurred_at همیشه ارسال می‌شود). */
    private function makeIncident(): Incident
    {
        $c = Candidate::factory()->create();
        return Incident::create([
            'candidate_id' => $c->id,
            'problem_description' => 'Audit seed incident description',
            'responsible_user_id' => $this->admin->id,
            'occurred_at' => now()->subMinute(),
        ]);
    }

    /**
     * بدنه‌ی معتبر ساخت سوال. مقدارهای difficulty/section_name/score از factory
     * سوال گرفته می‌شود تا حدس زده نشود.
     */
    private function questionPayload(int $subjectId, string $text): array
    {
        $tmp = Question::factory()->make();
        return [
            'subject_id' => $subjectId,
            'section_name' => $tmp->section_name ?? 'Section A',
            'question_type' => 'single',
            'question_text' => $text,
            'difficulty' => $tmp->difficulty ?? 'medium',
            'score' => $tmp->score ?? 1,
            'options' => [
                ['option_text' => 'AUDIT_OPT_A', 'is_correct' => true],
                ['option_text' => 'AUDIT_OPT_B', 'is_correct' => false],
            ],
        ];
    }

    /** ساخت سوال از طریق API تا نسخه‌ی اول (version 1) هم ساخته شود. */
    private function createQuestionViaApi(string $text): Question
    {
        $subject = Subject::factory()->create();
        $max = (int) Question::withTrashed()->max('id');
        $res = $this->json('POST', self::BASE . '/questions', $this->questionPayload((int) $subject->id, $text));
        $res->assertSuccessful();
        $q = Question::withTrashed()->where('id', '>', $max)->first();
        $this->assertNotNull($q, 'question fixture was not created');
        return $q;
    }

    /**
     * بدنه‌ی معتبر شیفت. فرمت start_time/end_time از factory شیفت استخراج می‌شود
     * (حدس زده نمی‌شود)؛ end_time همیشه بعد از start_time است.
     */
    private function shiftPayload(int $examId, int $labId, string $name): array
    {
        $attrs = Shift::factory()->make()->getAttributes();
        $start = (string) ($attrs['start_time'] ?? '08:00:00');

        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) === 1) {
            $end = Carbon::parse($start)->addHour()->format(strlen($start) === 5 ? 'H:i' : 'H:i:s');
        } else {
            $end = Carbon::parse($start)->addHours(2)->format('Y-m-d H:i:s');
        }
        $capacity = min(max((int) ($attrs['capacity'] ?? 30), 1), 500);

        return [
            'exam_id' => $examId,
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'lab_id' => $labId,
            'capacity' => $capacity,
        ];
    }

    /** کلاس مدل مربوط به کلید عملیات (برای بررسی حذف). */
    private function modelClassFor(string $op): string
    {
        $map = [
            'candidate' => Candidate::class,
            'computer' => Computer::class,
            'exam' => Exam::class,
            'question' => Question::class,
            'shift' => Shift::class,
            'incident' => Incident::class,
        ];
        return $map[explode('_', $op)[0]];
    }

    // ------------------------------------------------------------------
    // ابزارهای کمکی: بررسی audit
    // ------------------------------------------------------------------

    /**
     * بررسی «دقیقاً یک ردیف جدید»: افزایش کل ردیف‌ها برابر ۱ و تعداد کل ردیف‌های
     * همان action برابر مقدار انتظار؛ آخرین ردیف آن action را برمی‌گرداند.
     */
    private function expectOneAudit(int $before, string $action, int $actionTotal = 1): AuditLog
    {
        $this->assertSame($before + 1, AuditLog::count(), $action . ': expected exactly one new audit row');
        $this->assertSame($actionTotal, AuditLog::where('action', $action)->count(), $action . ': unexpected number of rows for this action');
        $row = AuditLog::where('action', $action)->orderByDesc('id')->first();
        $this->assertNotNull($row);
        return $row;
    }

    /** فیلدهای عمومی: کاربر، نقش، ماژول، نوع و شناسه هدف، IP. */
    private function assertCommon(AuditLog $row, string $module, string $targetSuffix, ?int $targetId): void
    {
        $this->assertSame((int) $this->admin->id, (int) $row->user_id);
        $this->assertSame('Super Admin', $row->role);
        $this->assertSame($module, $row->module);
        $this->assertTrue(str_ends_with((string) $row->target_type, $targetSuffix), 'target_type=' . $row->target_type);
        if ($targetId === null) {
            $this->assertNull($row->target_id);
        } else {
            $this->assertSame($targetId, (int) $row->target_id);
        }
        $this->assertNotEmpty($row->ip_address);
    }

    /** دیکد ستون JSON از مقدار خام DB (مستقل از cast مدل). */
    private function decode(AuditLog $row, string $column): ?array
    {
        $raw = $row->getRawOriginal($column);
        if ($raw === null) {
            return null;
        }
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($data) ? $data : null;
    }

    /** لیست changed_fields که سرویس داخل new_value نگه می‌دارد. */
    private function changedFields(AuditLog $row): array
    {
        $new = $this->decode($row, 'new_value');
        $fields = $new['changed_fields'] ?? [];
        return is_array($fields) ? $fields : [];
    }

    /**
     * عدم وجود داده‌ی حساس: هیچ کلید رشته‌ای حاوی واژه‌ی ممنوع نباشد و هیچ
     * مقدار (برگ) شامل مقادیر ممنوع نباشد. changed_fields فقط نام فیلد است
     * (کلید عددی) و از بررسی کلید مستثنی است.
     */
    private function assertAuditClean(AuditLog $row, array $forbiddenValues): void
    {
        $keys = [];
        $leaves = [];
        $this->walk($this->decode($row, 'old_value'), $keys, $leaves);
        $this->walk($this->decode($row, 'new_value'), $keys, $leaves);

        foreach ($keys as $key) {
            foreach (self::SENSITIVE_WORDS as $word) {
                $this->assertFalse(str_contains(strtolower($key), $word), 'sensitive key left in audit: ' . $key);
            }
        }
        foreach ($forbiddenValues as $secret) {
            $secret = (string) $secret;
            if ($secret === '') {
                continue;
            }
            foreach ($leaves as $leaf) {
                $this->assertFalse(str_contains($leaf, $secret), 'sensitive value leaked into audit: ' . $secret);
            }
        }
    }

    /** پیمایش بازگشتی آرایه برای جمع‌آوری کلیدهای رشته‌ای و مقادیر برگ. */
    private function walk(mixed $value, array &$keys, array &$leaves): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    $keys[] = $k;
                }
                $this->walk($v, $keys, $leaves);
            }
        } elseif ($value !== null) {
            $leaves[] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
    }

    /** جمع‌آوری مقدار کلیدهای مشخص (مثل plain_password) از پاسخ JSON. */
    private function collectByKey(mixed $data, array $keys, array &$found): void
    {
        if (! is_array($data)) {
            return;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $keys, true) && is_scalar($v) && $v !== '') {
                $found[] = (string) $v;
            }
            $this->collectByKey($v, $keys, $found);
        }
    }

    /**
     * اثرانگشت وضعیت جداول مرتبط (هش محتوای همه‌ی ردیف‌ها) برای اثبات Rollback کامل.
     */
    private function fingerprint(): array
    {
        $tables = [
            (new Candidate())->getTable(),
            (new CandidateAssignment())->getTable(),
            (new Computer())->getTable(),
            (new Exam())->getTable(),
            (new Question())->getTable(),
            (new Question())->versions()->getRelated()->getTable(),
            (new Shift())->getTable(),
            (new Incident())->getTable(),
            (new AuditLog())->getTable(),
        ];
        if (Schema::hasTable('question_options')) {
            $tables[] = 'question_options';
        }

        $result = [];
        foreach (array_values(array_unique($tables)) as $table) {
            $result[$table] = md5(DB::table($table)->orderBy('id')->get()->toJson());
        }
        return $result;
    }
}
