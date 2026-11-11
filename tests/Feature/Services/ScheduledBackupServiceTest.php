<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationType;
use App\Models\Backup;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ScheduledBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private ?PDO $externalPdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['backup.directory' => 'backups_test']);
    }

    protected function tearDown(): void
    {
        if ($this->externalPdo !== null) {
            try {
                $stmt = $this->externalPdo->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->execute([BackupService::LOCK_NAME]);
            } catch (Throwable) {
                // ignore
            }
            $this->externalPdo = null;
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeUser(string $roleName, bool $active = true): User
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(
            ['name' => $roleName],
            ['is_system_role' => $roleName === 'Super Admin']
        ));

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => $active,
        ]);
    }

    /**
     * Fakes the mysqldump process. If $stderr is null the dump succeeds and
     * the --result-file target is written; otherwise it fails with exit code 2.
     */
    private function fakeDump(?string $stderr = null): void
    {
        Process::fake(function ($process) use ($stderr) {
            if ($stderr !== null) {
                return Process::result('', $stderr, 2);
            }

            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            if (preg_match('/--result-file=("[^"]+"|\S+)/', $command, $m)) {
                $path = trim($m[1], '"');
                $dir = dirname($path);
                if (! is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($path, "-- fake dump\n");
            }

            return Process::result('');
        });
    }

    private function externalPdo(): PDO
    {
        if ($this->externalPdo === null) {
            $c = config('database.connections.'.config('database.default'));
            $this->externalPdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']),
                $c['username'],
                $c['password']
            );
        }

        return $this->externalPdo;
    }

    private function holdExternalLock(): void
    {
        $stmt = $this->externalPdo()->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([BackupService::LOCK_NAME]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'External lock could not be acquired.');
    }

    private function lockIsFree(): bool
    {
        $stmt = $this->externalPdo()->prepare('SELECT IS_FREE_LOCK(?)');
        $stmt->execute([BackupService::LOCK_NAME]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private function notificationsOf(NotificationType $type)
    {
        return Notification::query()->where('type', $type->value)->get();
    }

    private function captureThrowable(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('Expected an exception but none was thrown.');
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_scheduled_backup_success_is_recorded_as_scheduled_without_initiator(): void
    {
        $this->makeUser('Super Admin');
        $this->fakeDump();

        $backup = (new BackupService())->createFullBackup(null, BackupService::TRIGGER_SCHEDULED);

        $this->assertSame('completed', $backup->status);
        $this->assertSame('scheduled', $backup->triggered_by);
        $this->assertNull($backup->initiated_by);

        $fresh = Backup::query()->findOrFail($backup->id);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('scheduled', $fresh->triggered_by);
        $this->assertNull($fresh->initiated_by);
        $this->assertSame(1, Backup::query()->count());
        $this->assertTrue($this->lockIsFree());
    }

    public function test_scheduled_backup_completed_notifies_all_active_super_admins_only(): void
    {
        $adminA = $this->makeUser('Super Admin');
        $adminB = $this->makeUser('Super Admin');
        $this->makeUser('Super Admin', false);
        $this->makeUser('Exam Administrator');

        $this->fakeDump();

        $backup = (new BackupService())->createFullBackup(null, BackupService::TRIGGER_SCHEDULED);

        $rows = $this->notificationsOf(NotificationType::BackupCompleted);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            array_map('intval', $rows->pluck('notifiable_user_id')->all())
        );

        foreach ($rows as $row) {
            $this->assertSame('backup', $row->related_type);
            $this->assertSame($backup->id, (int) $row->related_id);
            $this->assertFalse((bool) $row->is_read);
        }

        $this->assertCount(0, $this->notificationsOf(NotificationType::BackupFailed));
    }

    public function test_scheduled_backup_dump_failure_records_failed_and_notifies_super_admins(): void
    {
        $adminA = $this->makeUser('Super Admin');
        $adminB = $this->makeUser('Super Admin');
        $this->makeUser('Exam Administrator');

        $this->fakeDump('mysqldump: simulated failure');

        $service = new BackupService();
        $e = $this->captureThrowable(
            fn () => $service->createFullBackup(null, BackupService::TRIGGER_SCHEDULED)
        );

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertNotInstanceOf(InvalidArgumentException::class, $e);

        $this->assertSame(1, Backup::query()->count());
        $backup = Backup::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $backup->status);
        $this->assertSame('scheduled', $backup->triggered_by);
        $this->assertNull($backup->initiated_by);

        $rows = $this->notificationsOf(NotificationType::BackupFailed);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            array_map('intval', $rows->pluck('notifiable_user_id')->all())
        );
        $this->assertCount(0, $this->notificationsOf(NotificationType::BackupCompleted));
        $this->assertTrue($this->lockIsFree());
    }

    public function test_scheduled_backup_lock_contention_records_failed_and_notifies_super_admins(): void
    {
        $adminA = $this->makeUser('Super Admin');
        $adminB = $this->makeUser('Super Admin');

        config(['backup.lock_wait_timeout' => 1]);
        $this->fakeDump();
        $this->holdExternalLock();

        $service = new BackupService();
        $e = $this->captureThrowable(
            fn () => $service->createFullBackup(null, BackupService::TRIGGER_SCHEDULED)
        );

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertStringContainsString('Could not acquire backup lock', $e->getMessage());

        $this->assertSame(1, Backup::query()->count());
        $backup = Backup::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $backup->status);
        $this->assertSame('scheduled', $backup->triggered_by);
        $this->assertNull($backup->initiated_by);

        $rows = $this->notificationsOf(NotificationType::BackupFailed);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            array_map('intval', $rows->pluck('notifiable_user_id')->all())
        );

        Process::assertNothingRan();
    }

    public function test_default_trigger_stays_manual_and_previous_call_styles_still_work(): void
    {
        $this->assertSame('manual', BackupService::TRIGGER_MANUAL);
        $this->assertSame('scheduled', BackupService::TRIGGER_SCHEDULED);

        $admin = $this->makeUser('Super Admin');
        $this->fakeDump();

        $service = new BackupService();

        $withUser = $service->createFullBackup($admin->id);
        $this->assertSame('manual', $withUser->triggered_by);
        $this->assertSame($admin->id, (int) $withUser->initiated_by);

        $withoutArgs = $service->createFullBackup();
        $this->assertSame('manual', $withoutArgs->triggered_by);
        $this->assertNull($withoutArgs->initiated_by);

        $explicitManual = $service->createFullBackup($admin->id, 'manual');
        $this->assertSame('manual', $explicitManual->triggered_by);
        $this->assertSame($admin->id, (int) $explicitManual->initiated_by);

        $this->assertSame(0, Backup::query()->where('triggered_by', 'scheduled')->count());
    }

    public function test_invalid_trigger_is_rejected_before_any_row_notification_or_process(): void
    {
        $this->makeUser('Super Admin');
        $this->fakeDump();

        $service = new BackupService();

        foreach (['weekly', '', 'MANUAL', 'Scheduled', ' manual'] as $badTrigger) {
            $e = $this->captureThrowable(fn () => $service->createFullBackup(null, $badTrigger));
            $this->assertInstanceOf(InvalidArgumentException::class, $e, 'Trigger: ['.$badTrigger.']');
        }

        $this->assertSame(0, Backup::query()->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertTrue($this->lockIsFree());
        Process::assertNothingRan();
    }

    public function test_scheduled_trigger_with_an_initiating_user_is_rejected(): void
    {
        $admin = $this->makeUser('Super Admin');
        $this->fakeDump();

        $service = new BackupService();
        $e = $this->captureThrowable(
            fn () => $service->createFullBackup($admin->id, BackupService::TRIGGER_SCHEDULED)
        );

        $this->assertInstanceOf(InvalidArgumentException::class, $e);
        $this->assertSame(0, Backup::query()->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertTrue($this->lockIsFree());
        Process::assertNothingRan();
    }
}