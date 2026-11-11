<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationType;
use App\Models\Backup;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BackupService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDO;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class BackupNotificationTest extends TestCase
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

    private function grantNotificationsView(string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $permission = Permission::unguarded(fn () => Permission::firstOrCreate([
            'module' => 'notifications',
            'action' => 'view',
        ]));

        $role->permissions()->attach($permission->id);
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

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_successful_backup_notifies_only_active_super_admins(): void
    {
        $adminA = $this->makeUser('Super Admin');
        $adminB = $this->makeUser('Super Admin');
        $this->makeUser('Super Admin', false);
        $this->makeUser('Exam Administrator');
        $this->grantNotificationsView('Exam Administrator');

        $this->fakeDump();

        $backup = (new BackupService())->createFullBackup($adminA->id);

        $this->assertSame('completed', $backup->status);

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
            $this->assertNotSame('', trim($row->title));
            $this->assertNotSame('', trim((string) $row->body));
        }

        $this->assertCount(0, $this->notificationsOf(NotificationType::BackupFailed));
    }

    public function test_failed_dump_notifies_super_admins_and_rethrows(): void
    {
        $adminA = $this->makeUser('Super Admin');
        $adminB = $this->makeUser('Super Admin');
        $this->makeUser('Super Admin', false);

        $this->fakeDump('mysqldump: simulated failure');

        $thrown = null;
        try {
            (new BackupService())->createFullBackup($adminA->id);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected RuntimeException was not thrown.');
        $this->assertStringStartsWith('Backup failed:', $thrown->getMessage());

        $backup = Backup::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $backup->status);

        $rows = $this->notificationsOf(NotificationType::BackupFailed);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            array_map('intval', $rows->pluck('notifiable_user_id')->all())
        );

        foreach ($rows as $row) {
            $this->assertSame('backup', $row->related_type);
            $this->assertSame($backup->id, (int) $row->related_id);
        }

        $this->assertCount(0, $this->notificationsOf(NotificationType::BackupCompleted));
    }

    public function test_failure_notification_does_not_leak_internal_details(): void
    {
        $this->makeUser('Super Admin');

        $this->fakeDump('mysqldump: Got error: SECRET_MARKER_XYZ');

        try {
            (new BackupService())->createFullBackup();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $backup = Backup::query()->latest('id')->firstOrFail();
        $rows = $this->notificationsOf(NotificationType::BackupFailed);
        $this->assertNotEmpty($rows);

        $forbidden = ['SECRET_MARKER_XYZ', 'mysqldump', 'backups_test', '.sql', '.zip', '\\', 'exit'];
        if (! empty($backup->file_path)) {
            $forbidden[] = $backup->file_path;
        }

        foreach ($rows as $row) {
            $text = $row->title.' '.$row->body;
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $text);
            }
        }
    }

    public function test_lock_acquisition_failure_creates_failed_notification(): void
    {
        $adminA = $this->makeUser('Super Admin');
        $adminB = $this->makeUser('Super Admin');

        config(['backup.lock_wait_timeout' => 1]);
        $this->fakeDump();
        $this->holdExternalLock();

        $thrown = null;
        try {
            (new BackupService())->createFullBackup($adminA->id);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected RuntimeException was not thrown.');
        $this->assertStringContainsString('Could not acquire backup lock', $thrown->getMessage());

        $backup = Backup::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $backup->status);

        $rows = $this->notificationsOf(NotificationType::BackupFailed);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            array_map('intval', $rows->pluck('notifiable_user_id')->all())
        );
        $this->assertCount(0, $this->notificationsOf(NotificationType::BackupCompleted));
    }

    public function test_completed_notification_is_dispatched_after_lock_is_released(): void
    {
        $this->makeUser('Super Admin');
        $this->fakeDump();

        $lockFreeDuringDispatch = null;
        $dispatchedType = null;

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function (NotificationType $type) use (&$lockFreeDuringDispatch, &$dispatchedType) {
                $dispatchedType = $type;
                $lockFreeDuringDispatch = $this->lockIsFree();

                return 1;
            });

        $backup = (new BackupService($mock))->createFullBackup();

        $this->assertSame('completed', $backup->status);
        $this->assertSame(NotificationType::BackupCompleted, $dispatchedType);
        $this->assertTrue($lockFreeDuringDispatch, 'Lock must be released before the completed notification is dispatched.');
    }

    public function test_failed_notification_is_dispatched_after_lock_is_released(): void
    {
        $this->makeUser('Super Admin');
        $this->fakeDump('mysqldump: simulated failure');

        $lockFreeDuringDispatch = null;
        $dispatchedType = null;

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function (NotificationType $type) use (&$lockFreeDuringDispatch, &$dispatchedType) {
                $dispatchedType = $type;
                $lockFreeDuringDispatch = $this->lockIsFree();

                return 1;
            });

        try {
            (new BackupService($mock))->createFullBackup();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(NotificationType::BackupFailed, $dispatchedType);
        $this->assertTrue($lockFreeDuringDispatch, 'Lock must be released before the failed notification is dispatched.');
    }

    public function test_notification_error_does_not_break_a_successful_backup(): void
    {
        Exceptions::fake();
        $this->makeUser('Super Admin');
        $this->fakeDump();

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('notify boom'));

        $backup = (new BackupService($mock))->createFullBackup();

        $this->assertSame('completed', $backup->status);
        $this->assertNotEmpty($backup->file_path);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'notify boom');
    }

    public function test_notification_error_does_not_mask_the_original_backup_failure(): void
    {
        Exceptions::fake();
        $this->makeUser('Super Admin');
        $this->fakeDump('mysqldump: simulated failure');

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('notify boom'));

        $thrown = null;
        try {
            (new BackupService($mock))->createFullBackup();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected RuntimeException was not thrown.');
        $this->assertStringStartsWith('Backup failed:', $thrown->getMessage());
        $this->assertStringNotContainsString('notify boom', $thrown->getMessage());

        $backup = Backup::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $backup->status);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'notify boom');
    }

    public function test_safety_backup_call_pattern_succeeds_with_notification(): void
    {
        $admin = $this->makeUser('Super Admin');
        $this->fakeDump();

        // Same call shape as RestoreBackupCommand uses for the safety backup.
        $safety = app(BackupService::class)->createFullBackup();

        $this->assertSame('completed', $safety->status);
        $this->assertNotEmpty($safety->file_path);

        $rows = $this->notificationsOf(NotificationType::BackupCompleted);
        $this->assertCount(1, $rows);
        $this->assertSame($admin->id, (int) $rows->first()->notifiable_user_id);
    }

    public function test_safety_backup_call_pattern_failure_is_catchable_and_notifies(): void
    {
        $admin = $this->makeUser('Super Admin');
        $this->fakeDump('mysqldump: simulated failure');

        $caught = null;
        try {
            app(BackupService::class)->createFullBackup();
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Failure must still be thrown to the caller.');

        $safety = Backup::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $safety->status);

        $rows = $this->notificationsOf(NotificationType::BackupFailed);
        $this->assertCount(1, $rows);
        $this->assertSame($admin->id, (int) $rows->first()->notifiable_user_id);
    }
}