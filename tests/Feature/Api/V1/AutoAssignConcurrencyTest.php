<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PDOException;
use Tests\TestCase;
use Throwable;

/**
 * تست واقعی هم‌زمانی و قفل ردیفی MySQL/InnoDB در سطح دیتابیس (Step 14.6.5c - Test 11d)
 *
 * این کلاس بدون استفاده از RefreshDatabase (بدون تراکنش بیرونی) اجرا می‌شود تا دو کانکشن مستقل
 * واقعی (conn1 و conn2) روی دیتابیس تست (azmoon_test) باز کرده و رفتار قفل‌های FOR UPDATE،
 * رعایت سلسله‌مراتب قفل‌ها (Shift -> CandidateAssignment -> Computer -> Attempt) و جلوگیری
 * از تخصیص یا استفاده هم‌زمان از یک کامپیوتر را به صورت واقعی اثبات کند.
 */
class AutoAssignConcurrencyTest extends TestCase
{
    /**
     * فهرست شناسه‌های رکوردهای ساخته‌شده در این تست برای پاک‌سازی ایزوله در بلوک finally.
     *
     * @var array<string, array<int, int>>
     */
    private array $createdIds = [
        'session_transfers'     => [],
        'attempts'              => [],
        'candidate_assignments' => [],
        'shifts'                => [],
        'exam_snapshots'        => [],
        'exams'                 => [],
        'computers'             => [],
        'candidates'            => [],
        'labs'                  => [],
        'subjects'              => [],
        'users'                 => [],
        'roles'                 => [],
    ];

    /**
     * سناریوی ۱۱d: تست هم‌زمانی واقعی با ۲ کانکشن مستقل MySQL/InnoDB روی azmoon_test.
     *
     * گام‌های اثبات‌شده در این تست:
     * ۱. بررسی پیش‌نیازهای اجرای واقعی قفل InnoDB (درایور mysql/mariadb، دیتابیس azmoon_test،
     *    موتور InnoDB برای جداول هدف، و transactionLevel === 0). در صورت عدم برقراری هر پیش‌نیاز،
     *    تست به صورت صریح Skipped علامت‌گذاری می‌شود (هرگز PASS جعلی ثبت نمی‌گردد).
     * ۲. درج و Commit داده‌های پایه به ترتیب توپولوژیک کلیدهای خارجی و اثبات اینکه هر دو کانکشن
     *    مستقل (دارای CONNECTION_ID متفاوت) داده‌های Commit شده را می‌بینند.
     * ۳. باز کردن تراکنش در conn1 (شبیه‌ساز تراکنش AutoAssignService::confirm در حال اجرا) و قفل کردن
     *    ردیف‌های هدف با SELECT ... FOR UPDATE به ترتیب سلسله‌مراتب استاندارد.
     * ۴. باز کردن تراکنش در conn2 با تنظیم SET SESSION innodb_lock_wait_timeout = 1 و تلاش برای قفل کردن
     *    همان ردیف Computer با SELECT ... FOR UPDATE؛ اثبات اینکه conn2 مسدود شده و خطای واقعی قفل
     *    InnoDB (کد 1205: Lock wait timeout exceeded) دریافت می‌کند.
     * ۵. تخصیص کامپیوتر در conn1 و Commit تراکنش اول؛ سپس بررسی مجدد در conn2 و اثبات اینکه پس از Commit،
     *    قفل آزاد شده و بررسی مجدد Snapshot/وضعیت در conn2 متوجه اشغال شدن کامپیوتر می‌شود و از تخصیص/شروع
     *    تکراری جلوگیری می‌کند.
     * ۶. پاک‌سازی کاملاً ایزوله و محدود به رکوردهای ساخته‌شده توسط همین تست در بلوک finally به ترتیب معکوس کلیدهای خارجی.
     */
    public function test_11d_real_two_connection_innodb_row_lock_prevents_concurrent_double_assignment(): void
    {
        $defaultConnection = DB::connection();
        $driver = strtolower((string) $defaultConnection->getDriverName());
        $dbName = (string) $defaultConnection->getDatabaseName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Real InnoDB concurrency test requires mysql/mariadb driver. Current driver: {$driver}");
        }

        if ($dbName !== 'azmoon_test') {
            $this->markTestSkipped("Security Guard: Concurrency test must ONLY run on 'azmoon_test'. Current DB: {$dbName}");
        }

        if ($defaultConnection->transactionLevel() !== 0) {
            $this->markTestSkipped('Outer transaction detected (transactionLevel > 0). Two-connection test requires transactionLevel === 0.');
        }

        $requiredTables = [
            'users',
            'subjects',
            'labs',
            'computers',
            'candidates',
            'exams',
            'exam_snapshots',
            'shifts',
            'candidate_assignments',
            'attempts',
            'session_transfers',
        ];

        foreach ($requiredTables as $table) {
            $tableInfo = $defaultConnection->selectOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbName, $table]
            );

            if (!$tableInfo) {
                $this->markTestSkipped("Required table '{$table}' does not exist in '{$dbName}'.");
            }

            $engine = strtoupper((string) ($tableInfo->ENGINE ?? ''));
            if ($engine !== 'INNODB') {
                $this->markTestSkipped("Table '{$table}' in '{$dbName}' uses engine '{$engine}', but INNODB is required.");
            }
        }

        // پیکربندی دو کانکشن کاملاً مجزا با همان تنظیمات دیتابیس تست
        $baseConfig = Config::get('database.connections.' . Config::get('database.default'));
        Config::set('database.connections.azmoon_test_conn1', $baseConfig);
        Config::set('database.connections.azmoon_test_conn2', $baseConfig);

        DB::purge('azmoon_test_conn1');
        DB::purge('azmoon_test_conn2');

        $conn1 = DB::connection('azmoon_test_conn1');
        $conn2 = DB::connection('azmoon_test_conn2');

        try {
            $conn1Id = (int) $conn1->selectOne('SELECT CONNECTION_ID() AS cid')->cid;
            $conn2Id = (int) $conn2->selectOne('SELECT CONNECTION_ID() AS cid')->cid;

            // اثبات اینکه دو کانکشن در سطح MySQL واقعاً دو نشست مستقل هستند
            $this->assertNotSame($conn1Id, $conn2Id, 'conn1 and conn2 must have distinct MySQL CONNECTION_ID values.');
            $this->assertSame(0, $conn1->transactionLevel());
            $this->assertSame(0, $conn2->transactionLevel());

            // ساخت و Commit داده‌های پایه به ترتیب توپولوژیک کلیدهای خارجی:
            // users -> subjects -> labs -> computers -> candidates -> exams -> exam_snapshots -> shifts -> candidate_assignments
            $suffix = 'CC_' . strtoupper(str_replace('.', '', uniqid('', true)));

            $creatorUserId = $this->insertCommittedRow('users', [
                'name'      => 'Concurrency Creator ' . $suffix,
                'username'  => 'cc_usr_' . strtolower($suffix),
                'email'     => 'cc_usr_' . strtolower($suffix) . '@example.test',
                'password'  => Hash::make('Pass123!'),
                'role_id'   => null,
                'is_active' => 1,
            ]);

            $subjectId = $this->insertCommittedRow('subjects', [
                'name'       => 'Concurrency Subject ' . $suffix,
                'code'       => 'SUBJ_' . $suffix,
                'faculty_id' => null,
                'is_active'  => 1,
                'created_by' => null,
            ]);

            $labId = $this->insertCommittedRow('labs', [
                'name'     => 'Concurrency Lab ' . $suffix,
                'location' => 'Block C',
            ]);

            $comp1Id = $this->insertCommittedRow('computers', [
                'lab_id'        => $labId,
                'computer_code' => 'PC-1-' . $suffix,
                'ip_address'    => '10.99.1.11',
                'hostname'      => 'pc1-' . strtolower($suffix),
                'status'        => 'online',
            ]);

            $comp2Id = $this->insertCommittedRow('computers', [
                'lab_id'        => $labId,
                'computer_code' => 'PC-2-' . $suffix,
                'ip_address'    => '10.99.1.12',
                'hostname'      => 'pc2-' . strtolower($suffix),
                'status'        => 'online',
            ]);

            $cand1Id = $this->insertCommittedRow('candidates', [
                'candidate_code' => 'C1-' . $suffix,
                'name'           => 'Candidate One ' . $suffix,
                'username'       => 'c1_' . strtolower($suffix),
                'password'       => Hash::make('Pass123!'),
                'national_id'    => (string) random_int(1000000000, 9999999999),
                'status'         => 'present',
            ]);

            $cand2Id = $this->insertCommittedRow('candidates', [
                'candidate_code' => 'C2-' . $suffix,
                'name'           => 'Candidate Two ' . $suffix,
                'username'       => 'c2_' . strtolower($suffix),
                'password'       => Hash::make('Pass123!'),
                'national_id'    => (string) random_int(1000000000, 9999999999),
                'status'         => 'present',
            ]);

            $examId = $this->insertCommittedRow('exams', [
                'subject_id'       => $subjectId,
                'created_by'       => $creatorUserId,
                'title'            => 'Concurrency Exam ' . $suffix,
                'duration_minutes' => 60,
                'total_marks'      => 100,
                'pass_marks'       => 50,
                'status'           => 'published',
            ]);

            $snapshotId = $this->insertCommittedRow('exam_snapshots', [
                'exam_id'      => $examId,
                'generated_at' => now(),
                'is_immutable' => 1,
            ]);

            $shiftId = $this->insertCommittedRow('shifts', [
                'exam_id'    => $examId,
                'lab_id'     => $labId,
                'name'       => 'Shift ' . $suffix,
                'start_time' => now()->subMinutes(10),
                'end_time'   => now()->addMinutes(90),
                'capacity'   => 10,
            ]);

            $assign1Id = $this->insertCommittedRow('candidate_assignments', [
                'candidate_id' => $cand1Id,
                'exam_id'      => $examId,
                'shift_id'     => $shiftId,
                'lab_id'       => $labId,
                'computer_id'  => null,
                'assigned_by'  => null,
                'assigned_at'  => null,
            ]);

            $assign2Id = $this->insertCommittedRow('candidate_assignments', [
                'candidate_id' => $cand2Id,
                'exam_id'      => $examId,
                'shift_id'     => $shiftId,
                'lab_id'       => $labId,
                'computer_id'  => null,
                'assigned_by'  => null,
                'assigned_at'  => null,
            ]);

            // اثبات اینکه هر دو کانکشن مستقل، ردیف‌های Commit شده را می‌بینند
            $visibleOnConn1 = $conn1->table('computers')->where('id', $comp1Id)->exists()
                && $conn1->table('candidate_assignments')->where('id', $assign1Id)->exists();
            $visibleOnConn2 = $conn2->table('computers')->where('id', $comp1Id)->exists()
                && $conn2->table('candidate_assignments')->where('id', $assign1Id)->exists();

            $this->assertTrue($visibleOnConn1, 'Committed fixtures must be visible on conn1.');
            $this->assertTrue($visibleOnConn2, 'Committed fixtures must be visible on conn2.');

            // تنظیم زمان انتظار قفل در conn2 روی ۱ ثانیه تا برخورد قفل سریع و قطعی آزمایش شود
            $conn2->statement('SET SESSION innodb_lock_wait_timeout = 1');

            // گام ۱: شروع تراکنش در conn1 و اخذ قفل‌ها طبق سلسله‌مراتب استاندارد:
            // Shift -> CandidateAssignment -> Computer
            $conn1->beginTransaction();

            $lockedShift1 = $conn1->selectOne(
                'SELECT id, capacity FROM shifts WHERE id = ? FOR UPDATE',
                [$shiftId]
            );
            $this->assertNotNull($lockedShift1);

            $lockedAssignments1 = $conn1->select(
                'SELECT id, computer_id FROM candidate_assignments WHERE shift_id = ? ORDER BY id ASC FOR UPDATE',
                [$shiftId]
            );
            $this->assertCount(2, $lockedAssignments1);

            $lockedComputers1 = $conn1->select(
                'SELECT id, status FROM computers WHERE id IN (?, ?) ORDER BY id ASC FOR UPDATE',
                [$comp1Id, $comp2Id]
            );
            $this->assertCount(2, $lockedComputers1);

            // گام ۲: در حالی که conn1 قفل‌ها را در اختیار دارد، conn2 تراکنش باز می‌کند
            // و تلاش می‌کند همان کامپیوتر اول ($comp1Id) را با FOR UPDATE قفل کند.
            $conn2->beginTransaction();

            $lockTimeoutCaught = false;
            $caughtErrorMessage = '';

            try {
                $conn2->selectOne(
                    'SELECT id, status FROM computers WHERE id = ? FOR UPDATE',
                    [$comp1Id]
                );
            } catch (QueryException|PDOException $e) {
                $lockTimeoutCaught = true;
                $caughtErrorMessage = $e->getMessage();
            }

            $this->assertTrue(
                $lockTimeoutCaught,
                'conn2 MUST block on conn1 FOR UPDATE lock and throw lock wait timeout (1205).'
            );
            $this->assertTrue(
                str_contains($caughtErrorMessage, '1205') || str_contains(strtolower($caughtErrorMessage), 'lock wait timeout'),
                "Expected MySQL 1205 Lock wait timeout error on conn2, got: {$caughtErrorMessage}"
            );

            $conn2->rollBack();

            // گام ۳: اکنون conn1 تخصیص کامپیوتر اول به داوطلب اول را انجام می‌دهد و وضعیت کامپیوتر را به exam تغییر می‌دهد
            $affected1 = $conn1->table('candidate_assignments')
                ->where('id', $assign1Id)
                ->where('shift_id', $shiftId)
                ->whereNull('computer_id')
                ->update([
                    'computer_id' => $comp1Id,
                    'assigned_at' => now(),
                    'updated_at'  => now(),
                ]);
            $this->assertSame(1, $affected1);

            $conn1->table('computers')
                ->where('id', $comp1Id)
                ->update([
                    'status'     => 'exam',
                    'updated_at' => now(),
                ]);

            $conn1->commit();

            // گام ۴: پس از Commit تراکنش اول، conn2 دوباره وارد تراکنش شده و قفل ردیف‌ها را می‌گیرد؛
            // در بازبینی پس از قفل (Snapshot Re-validation)، می‌بیند که $assign1Id قبلاً computer_id گرفته
            // و $comp1Id در وضعیت exam (و رزرو شده در شیفت) است؛ بنابراین فقط $comp2Id به $assign2Id می‌رسد.
            $conn2->beginTransaction();

            $lockedAssignment1OnConn2 = $conn2->selectOne(
                'SELECT id, computer_id FROM candidate_assignments WHERE id = ? FOR UPDATE',
                [$assign1Id]
            );
            $lockedComp1OnConn2 = $conn2->selectOne(
                'SELECT id, status FROM computers WHERE id = ? FOR UPDATE',
                [$comp1Id]
            );

            $this->assertSame($comp1Id, (int) $lockedAssignment1OnConn2->computer_id);
            $this->assertSame('exam', (string) $lockedComp1OnConn2->status);

            // تلاش مشروط conn2 برای آپدیت مجدد assign1Id با شرط whereNull('computer_id') باید 0 ردیف را تغییر دهد
            $doubleUpdateAffected = $conn2->table('candidate_assignments')
                ->where('id', $assign1Id)
                ->where('shift_id', $shiftId)
                ->whereNull('computer_id')
                ->update(['computer_id' => $comp2Id]);

            $this->assertSame(0, $doubleUpdateAffected, 'Conditional update whereNull(computer_id) must prevent overwriting conn1 assignment.');

            // تخصیص کامپیوتر دوم که واقعاً آزاد است به داوطلب دوم در conn2
            $secondAssignAffected = $conn2->table('candidate_assignments')
                ->where('id', $assign2Id)
                ->where('shift_id', $shiftId)
                ->whereNull('computer_id')
                ->update([
                    'computer_id' => $comp2Id,
                    'assigned_at' => now(),
                    'updated_at'  => now(),
                ]);
            $this->assertSame(1, $secondAssignAffected);

            $conn2->commit();

            // اثبات وضعیت نهایی دیتابیس: هر داوطلب یک کامپیوتر یکتا دارد و هیچ تداخلی رخ نداده است
            $finalAssign1 = (int) $defaultConnection->table('candidate_assignments')->where('id', $assign1Id)->value('computer_id');
            $finalAssign2 = (int) $defaultConnection->table('candidate_assignments')->where('id', $assign2Id)->value('computer_id');

            $this->assertSame($comp1Id, $finalAssign1);
            $this->assertSame($comp2Id, $finalAssign2);
            $this->assertNotSame($finalAssign1, $finalAssign2);
        } finally {
            // بستن ایمن هر تراکنش باز احتمالی روی conn1 و conn2 پیش از پاک‌سازی رکوردهای تست
            try {
                while ($conn1->transactionLevel() > 0) {
                    $conn1->rollBack();
                }
            } catch (Throwable) {
            }

            try {
                while ($conn2->transactionLevel() > 0) {
                    $conn2->rollBack();
                }
            } catch (Throwable) {
            }

            $this->cleanupCreatedFixtures();

            DB::disconnect('azmoon_test_conn1');
            DB::disconnect('azmoon_test_conn2');
        }
    }

    /**
     * درج رکورد به صورت Commit شده (خارج از تراکنش) و ثبت شناسه آن برای پاک‌سازی ایزوله.
     * هیچ مقدار ثابت یا فرضی برای کلیدهای خارجی قرار داده نمی‌شود.
     *
     * @param string               $table
     * @param array<string, mixed> $overrides
     */
    private function insertCommittedRow(string $table, array $overrides): int
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

        $id = (int) DB::table($table)->insertGetId($row);
        $this->createdIds[$table][] = $id;

        return $id;
    }

    /**
     * پاک‌سازی ایزوله و دقیق فقط رکوردهایی که توسط همین تست ساخته شده‌اند،
     * به ترتیب معکوس وابستگی‌های کلید خارجی (Reverse Topological Order):
     * session_transfers -> attempts -> candidate_assignments -> shifts -> exam_snapshots
     * -> exams -> computers -> candidates -> labs -> subjects -> users -> roles
     */
    private function cleanupCreatedFixtures(): void
    {
        $deleteOrder = [
            'session_transfers',
            'attempts',
            'candidate_assignments',
            'shifts',
            'exam_snapshots',
            'exams',
            'computers',
            'candidates',
            'labs',
            'subjects',
            'users',
            'roles',
        ];

        // اگر در طول تست رکوردهایی در attempts یا session_transfers برای تخصیص‌های همین تست ایجاد شده باشد، آن‌ها را نیز جمع‌آوری می‌کنیم
        if (!empty($this->createdIds['candidate_assignments'])) {
            try {
                $attemptIds = DB::table('attempts')
                    ->whereIn('candidate_assignment_id', $this->createdIds['candidate_assignments'])
                    ->pluck('id')
                    ->map(static fn ($id) => (int) $id)
                    ->all();

                if (!empty($attemptIds)) {
                    $this->createdIds['attempts'] = array_values(array_unique(array_merge(
                        $this->createdIds['attempts'],
                        $attemptIds
                    )));

                    $transferIds = DB::table('session_transfers')
                        ->whereIn('attempt_id', $this->createdIds['attempts'])
                        ->pluck('id')
                        ->map(static fn ($id) => (int) $id)
                        ->all();

                    if (!empty($transferIds)) {
                        $this->createdIds['session_transfers'] = array_values(array_unique(array_merge(
                            $this->createdIds['session_transfers'],
                            $transferIds
                        )));
                    }
                }
            } catch (Throwable) {
            }
        }

        foreach ($deleteOrder as $table) {
            $ids = $this->createdIds[$table] ?? [];
            if (!empty($ids)) {
                try {
                    DB::table($table)->whereIn('id', $ids)->delete();
                } catch (Throwable) {
                }
            }
        }
    }
}