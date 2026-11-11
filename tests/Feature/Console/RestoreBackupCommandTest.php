<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Setting;
use App\Services\BackupService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PDO;
use PDOException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;
use Throwable;
use ZipArchive;

class RestoreBackupCommandTest extends TestCase
{
    private const TEMP_DB_PATTERN = '/^azmoon_restore_test_[a-f0-9]{12}$/';
    private const PROTECTED_DATABASES = ['azmoon', 'azmoon_test'];
    private const BACKUP_DIR = 'backups_restore_cmd_test';
    private const PROMPT = 'Type the exact database name to confirm the restore';

    private static ?string $sharedDatabase = null;
    private static ?array $adminConfig = null;

    private ?string $dedicatedDatabase = null;
    private string $originalDatabase = '';
    private string $connectionName = '';
    private string $realMysqlPath = 'mysql';
    private string $realMysqldumpPath = 'mysqldump';
    private array $ranCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionName = (string) config('database.default');
        $this->originalDatabase = (string) config("database.connections.{$this->connectionName}.database");
        $this->realMysqlPath = (string) config('backup.mysql_path', 'mysql');
        $this->realMysqldumpPath = (string) config('backup.mysqldump_path', 'mysqldump');

        if (config("database.connections.{$this->connectionName}.driver") !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection.');
        }

        $this->useSharedTemporaryDatabase();

        Storage::fake('local');
        config([
            'backup.disk' => 'local',
            'backup.directory' => self::BACKUP_DIR,
            'backup.mysqldump_path' => 'fake-mysqldump',
            'backup.mysql_path' => 'fake-mysql',
            'backup.lock_wait_timeout' => 1,
        ]);

        DB::connection($this->connectionName)->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            $connection = DB::connection($this->connectionName);

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // Best effort.
        }

        if ($this->connectionName !== '') {
            DB::purge($this->connectionName);
        }

        if ($this->dedicatedDatabase !== null && self::$adminConfig !== null) {
            self::dropTemporaryDatabase(self::$adminConfig, $this->dedicatedDatabase);
            $this->dedicatedDatabase = null;
        }

        if ($this->connectionName !== '' && $this->originalDatabase !== '') {
            config(["database.connections.{$this->connectionName}.database" => $this->originalDatabase]);
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedDatabase !== null && self::$adminConfig !== null) {
            self::dropTemporaryDatabase(self::$adminConfig, self::$sharedDatabase);
        }

        self::$sharedDatabase = null;
        self::$adminConfig = null;

        parent::tearDownAfterClass();
    }

    // ─── Pre-validation: nothing destructive, no safety backup, no process ───

    public function test_database_option_is_required(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        $exit = Artisan::call('backup:restore', ['backup' => $backup->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--database', Artisan::output());
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_mismatched_database_name_is_rejected_before_any_backup(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        [$exit] = $this->runRestore($backup, ['--database' => 'some_other_database']);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count(), 'No safety backup may be created on a database mismatch.');
    }

    public function test_non_default_connection_is_rejected_before_any_backup(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        [$exit] = $this->runRestore($backup, ['--connection' => 'mariadb']);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_unknown_backup_id_is_rejected(): void
    {
        $this->fakeProcesses();

        [$exit] = $this->runRestore(999999);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
    }

    public function test_only_completed_backups_can_be_restored(): void
    {
        $this->fakeProcesses();

        foreach (['pending', 'running', 'failed'] as $status) {
            $backup = $this->makeBackup(['status' => $status]);

            [$exit] = $this->runRestore($backup);

            $this->assertSame(1, $exit, "Status {$status} must be rejected.");
        }

        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(3, Backup::count());
    }

    public function test_only_full_backups_can_be_restored(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup(['backup_type' => 'incremental']);

        [$exit] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_file_outside_backup_directory_is_rejected(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup([], 'dump.sql', 'outside_restore_src.zip');

        [$exit] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_sibling_directory_sharing_the_same_prefix_is_rejected(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup([], 'dump.sql', self::BACKUP_DIR . '_evil/restore_src.zip');
        Storage::disk('local')->makeDirectory(self::BACKUP_DIR);

        [$exit] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
    }

    public function test_path_traversal_sequence_is_rejected(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup([], 'dump.sql', 'outside_traversal.zip');
        Storage::disk('local')->makeDirectory(self::BACKUP_DIR);
        $backup->update(['file_path' => self::BACKUP_DIR . '/../outside_traversal.zip']);

        [$exit] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_checksum_mismatch_is_rejected(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup(['checksum' => str_repeat('a', 64)]);

        [$exit, $output] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Checksum', $output);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_archive_with_unexpected_content_is_rejected(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup([], 'dump.txt');

        [$exit] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    // ─── Production confirmation (no --force) ────────────────────────────────

    public function test_force_option_does_not_exist(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        $this->expectException(InvalidOptionException::class);

        Artisan::call('backup:restore', [
            'backup' => $backup->id,
            '--database' => $this->targetDatabase(),
            '--force' => true,
        ]);
    }

    public function test_production_rejects_wrong_database_confirmation(): void
    {
        $this->app['env'] = 'production';
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        $this->artisan('backup:restore', ['backup' => $backup->id, '--database' => $this->targetDatabase()])
            ->expectsQuestion(self::PROMPT, 'wrong_database_name')
            ->assertExitCode(1);

        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_production_refuses_non_interactive_mode(): void
    {
        $this->app['env'] = 'production';
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        [$exit] = $this->runRestore($backup, ['--no-interaction' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->ranBinaries());
        $this->assertSame(1, Backup::count());
    }

    public function test_production_proceeds_after_exact_database_confirmation(): void
    {
        $this->app['env'] = 'production';
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        $this->artisan('backup:restore', ['backup' => $backup->id, '--database' => $this->targetDatabase()])
            ->expectsQuestion(self::PROMPT, $this->targetDatabase())
            ->assertExitCode(0);

        $this->assertSame(['fake-mysqldump', 'fake-mysql'], $this->ranBinaries());
    }

    // ─── Safety backup, success, failure, lock ───────────────────────────────

    public function test_safety_backup_failure_aborts_without_restoring(): void
    {
        $this->fakeProcesses(null, 1);
        $backup = $this->makeBackup();

        [$exit, $output] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Safety backup failed', $output);
        $this->assertSame(['fake-mysqldump'], $this->ranBinaries(), 'mysql must never run after a failed safety backup.');
        $this->assertSame(0, AuditLog::where('module', 'backups')->count());
    }

    public function test_successful_restore_resyncs_registry_audits_and_releases_lock(): void
    {
        $marker = 'S3cr3t_Restore_Marker';
        config(["database.connections.{$this->connectionName}.password" => $marker]);

        $backup = $this->makeBackup();

        // Simulate the restore overwriting the backups table.
        $this->fakeProcesses(function () {
            DB::table('backups')->delete();

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        [$exit, $output] = $this->runRestore($backup);

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['fake-mysqldump', 'fake-mysql'], $this->ranBinaries());

        $mysql = $this->ranCommands[1];
        $this->assertSame($this->targetDatabase(), end($mysql['command']));

        foreach ($mysql['command'] as $part) {
            $this->assertStringNotContainsString($marker, $part);
        }
        $this->assertStringNotContainsString('--password', implode(' ', $mysql['command']));

        if ($mysql['environment'] !== []) {
            $this->assertSame($marker, $mysql['environment']['MYSQL_PWD'] ?? null);
        }

        $this->assertStringNotContainsString($marker, $output);

        // Registry re-synchronised: target + safety backup both present and tracked.
        $this->assertSame(2, Backup::count());
        $restored = Backup::find($backup->id);
        $this->assertNotNull($restored);
        $this->assertSame('completed', $restored->status);
        $this->assertSame($backup->file_path, $restored->file_path);

        $safety = Backup::where('id', '!=', $backup->id)->first();
        $this->assertNotNull($safety);
        $this->assertSame('completed', $safety->status);
        Storage::disk('local')->assertExists($safety->file_path);

        $log = AuditLog::where('action', 'Backup:restored')->first();
        $this->assertNotNull($log);
        $this->assertSame('backups', $log->module);
        $this->assertSame($backup->id, (int) $log->target_id);
        $this->assertSame($safety->id, $log->new_value['safety_backup_id']);
        $this->assertStringNotContainsString($marker, json_encode($log->new_value));

        $this->assertLockIsFree();
    }

    public function test_restore_process_failure_is_reported_honestly_without_leaking_secrets(): void
    {
        $marker = 'S3cr3t_Failure_Marker';
        config(["database.connections.{$this->connectionName}.password" => $marker]);

        $backup = $this->makeBackup();

        $this->fakeProcesses(function () use ($marker) {
            DB::table('backups')->delete();

            return Process::result(output: '', errorOutput: "ERROR 1064 using password {$marker}", exitCode: 1);
        });

        [$exit, $output] = $this->runRestore($backup);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Restore FAILED', $output);
        $this->assertStringContainsString('No automatic rollback', $output);
        $this->assertStringContainsString('safety backup', strtolower($output));
        $this->assertStringNotContainsString($marker, $output);

        $this->assertSame(1, AuditLog::where('action', 'Backup:restore_failed')->count());
        $this->assertSame(0, AuditLog::where('action', 'Backup:restored')->count());
        $this->assertStringNotContainsString($marker, json_encode(AuditLog::all()->toArray()));

        $this->assertSame(2, Backup::count(), 'Registry must be re-synchronised even after a failed restore.');
        $this->assertNotNull(Backup::find($backup->id));

        $this->assertLockIsFree();
    }

    public function test_restore_is_refused_while_another_operation_holds_the_lock(): void
    {
        $this->fakeProcesses();
        $backup = $this->makeBackup();

        $holder = $this->acquireRawLock();

        try {
            [$exit] = $this->runRestore($backup);
        } finally {
            $this->releaseRawLock($holder);
        }

        $this->assertSame(1, $exit);
        $this->assertNotContains('fake-mysql', $this->ranBinaries());
        $this->assertSame(0, AuditLog::where('action', 'Backup:restored')->count());
    }

    // ─── Real restore on a DEDICATED temporary database ──────────────────────

    public function test_real_restore_on_dedicated_temporary_database(): void
    {
        foreach ([$this->realMysqlPath, $this->realMysqldumpPath] as $binary) {
            try {
                $ok = Process::run([$binary, '--version'])->successful();
            } catch (Throwable) {
                $ok = false;
            }

            if (!$ok) {
                $this->markTestSkipped("Executable '{$binary}' is not available in this environment.");
            }
        }

        config([
            'backup.mysql_path' => $this->realMysqlPath,
            'backup.mysqldump_path' => $this->realMysqldumpPath,
            'backup.lock_wait_timeout' => 10,
        ]);

        $temporary = $this->switchToDedicatedTemporaryDatabase();

        // Re-verify the active database right before any data work or restore.
        $this->assertSame($temporary, DB::connection($this->connectionName)->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertNotContains($temporary, self::PROTECTED_DATABASES);

        Setting::factory()->create(['key' => 'restore.marker', 'value' => 'original']);

        $backup = app(BackupService::class)->createFullBackup();
        $this->assertSame('completed', $backup->status);

        Setting::where('key', 'restore.marker')->update(['value' => 'mutated']);
        Setting::factory()->create(['key' => 'restore.extra', 'value' => 'should_disappear']);

        $this->assertSame($temporary, DB::connection($this->connectionName)->selectOne('SELECT DATABASE() AS db')->db);

        $exit = Artisan::call('backup:restore', ['backup' => $backup->id, '--database' => $temporary]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        $this->assertSame('original', Setting::where('key', 'restore.marker')->value('value'));
        $this->assertFalse(Setting::where('key', 'restore.extra')->exists());

        $restored = Backup::find($backup->id);
        $this->assertNotNull($restored);
        $this->assertSame('completed', $restored->status);
        $this->assertNotNull($restored->file_path);

        $safety = Backup::where('id', '!=', $backup->id)->where('status', 'completed')->first();
        $this->assertNotNull($safety, 'The safety backup record must remain tracked.');
        Storage::disk('local')->assertExists($safety->file_path);

        $this->assertTrue(
            AuditLog::where('action', 'Backup:restored')->where('target_id', $backup->id)->exists()
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function targetDatabase(): string
    {
        return (string) config("database.connections.{$this->connectionName}.database");
    }

    private function runRestore(Backup|int $backup, array $options = []): array
    {
        $id = $backup instanceof Backup ? $backup->id : $backup;

        $exit = Artisan::call('backup:restore', array_merge(
            ['backup' => $id, '--database' => $this->targetDatabase()],
            $options
        ));

        return [$exit, Artisan::output()];
    }

    private function ranBinaries(): array
    {
        return array_column($this->ranCommands, 'binary');
    }

    private function fakeProcesses(?callable $mysqlHandler = null, int $dumpExit = 0): void
    {
        $this->ranCommands = [];

        Process::fake(function (PendingProcess $process) use ($mysqlHandler, $dumpExit) {
            $command = is_array($process->command) ? $process->command : [$process->command];
            $command = array_map('strval', $command);
            $binary = $command[0] ?? '';

            $environment = (property_exists($process, 'environment') && is_array($process->environment ?? null))
                ? $process->environment
                : [];

            $this->ranCommands[] = ['binary' => $binary, 'command' => $command, 'environment' => $environment];

            if ($binary === 'fake-mysqldump') {
                if ($dumpExit !== 0) {
                    return Process::result(output: '', errorOutput: 'simulated dump failure', exitCode: $dumpExit);
                }

                foreach ($command as $part) {
                    if (str_starts_with($part, '--result-file=')) {
                        file_put_contents(substr($part, strlen('--result-file=')), "-- fake dump\nCREATE TABLE fake (id INT);\n");
                    }
                }

                return Process::result(output: '', errorOutput: '', exitCode: 0);
            }

            if ($binary === 'fake-mysql') {
                return $mysqlHandler
                    ? $mysqlHandler($process, $command)
                    : Process::result(output: '', errorOutput: '', exitCode: 0);
            }

            return Process::result(output: '', errorOutput: 'unexpected binary', exitCode: 99);
        });
    }

    private function makeBackup(array $overrides = [], string $entryName = 'dump.sql', ?string $relativePath = null): Backup
    {
        $relativePath ??= self::BACKUP_DIR . '/restore_src_' . uniqid() . '.zip';
        $disk = Storage::disk('local');

        if (dirname($relativePath) !== '.') {
            $disk->makeDirectory(dirname($relativePath));
        }

        $absolute = $disk->path($relativePath);

        $zip = new ZipArchive();
        $zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, "CREATE TABLE t (id INT);\n");
        $zip->close();

        return Backup::factory()->create(array_merge([
            'file_path' => $relativePath,
            'checksum' => hash_file('sha256', $absolute),
            'size_bytes' => filesize($absolute),
            'status' => 'completed',
            'backup_type' => 'full',
        ], $overrides));
    }

    // ─── Temporary database management ───────────────────────────────────────

    private function useSharedTemporaryDatabase(): void
    {
        if (self::$sharedDatabase === null) {
            self::$adminConfig = (array) config("database.connections.{$this->connectionName}");

            $name = $this->createTemporaryDatabase();
            self::$sharedDatabase = $name;

            $this->pointConnectionTo($name);
            Artisan::call('migrate', ['--force' => true]);

            return;
        }

        $this->pointConnectionTo(self::$sharedDatabase);
    }

    private function switchToDedicatedTemporaryDatabase(): string
    {
        $connection = DB::connection($this->connectionName);

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $name = $this->createTemporaryDatabase();
        $this->dedicatedDatabase = $name;
        $this->assertNotSame(self::$sharedDatabase, $name);

        // Verifies the active database BEFORE any migration or restore.
        $this->pointConnectionTo($name);
        Artisan::call('migrate', ['--force' => true]);

        return $name;
    }

    private function createTemporaryDatabase(): string
    {
        $name = 'azmoon_restore_test_' . bin2hex(random_bytes(6));
        $this->assertSafeTemporaryName($name);

        try {
            self::adminPdo(self::$adminConfig)
                ->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException) {
            $this->markTestSkipped('Cannot create a temporary database (missing CREATE DATABASE privilege or server unavailable).');
        }

        return $name;
    }

    private function pointConnectionTo(string $name): void
    {
        $this->assertSafeTemporaryName($name);

        config(["database.connections.{$this->connectionName}.database" => $name]);
        DB::purge($this->connectionName);

        $connection = DB::connection($this->connectionName);

        $this->assertSame($name, $connection->getDatabaseName());
        $this->assertSame($name, $connection->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertNotContains($name, self::PROTECTED_DATABASES);
    }

    private function assertSafeTemporaryName(string $name): void
    {
        $this->assertMatchesRegularExpression(self::TEMP_DB_PATTERN, $name);
        $this->assertNotContains($name, self::PROTECTED_DATABASES);
    }

    private static function adminPdo(array $config): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function dropTemporaryDatabase(array $config, string $name): void
    {
        // Only databases created by this test class can ever be dropped.
        if (preg_match(self::TEMP_DB_PATTERN, $name) !== 1 || in_array($name, self::PROTECTED_DATABASES, true)) {
            return;
        }

        try {
            self::adminPdo($config)->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (Throwable) {
            fwrite(STDERR, "WARNING: could not drop temporary database {$name}\n");
        }
    }

    // ─── Raw lock helpers (separate session) ─────────────────────────────────

    private function acquireRawLock(): PDO
    {
        $pdo = self::adminPdo(self::$adminConfig);

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?) AS result');
        $stmt->execute([BackupService::LOCK_NAME, 1]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);

        if ((int) ($row->result ?? 0) !== 1) {
            $this->fail('Could not acquire the simulated external lock.');
        }

        return $pdo;
    }

    private function releaseRawLock(PDO $pdo): void
    {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([BackupService::LOCK_NAME]);
    }

    private function assertLockIsFree(): void
    {
        $pdo = $this->acquireRawLock();
        $this->releaseRawLock($pdo);
        $this->assertTrue(true);
    }
}