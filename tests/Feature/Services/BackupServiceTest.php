<?php

namespace Tests\Feature\Services;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $testDirectory = 'backups_test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['backup.directory' => $this->testDirectory]);
    }

    public function test_failed_mysqldump_marks_backup_failed_and_cleans_up(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'simulated mysqldump failure', exitCode: 1),
        ]);

        $service = new BackupService();

        try {
            $service->createFullBackup();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Backup failed', $e->getMessage());
        }

        $backup = Backup::query()->latest('id')->first();

        $this->assertNotNull($backup);
        $this->assertSame('failed', $backup->status);
        $this->assertNotNull($backup->failure_reason);
        $this->assertStringContainsString('simulated mysqldump failure', $backup->failure_reason);
        $this->assertNull($backup->file_path);

        $remainingFiles = Storage::disk('local')->allFiles($this->testDirectory);
        $this->assertCount(0, $remainingFiles, 'No files should remain on disk after a failed backup.');
    }

    public function test_lock_is_released_after_failure_allowing_a_subsequent_attempt(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'first attempt fails', exitCode: 1),
        ]);

        $service = new BackupService();

        try {
            $service->createFullBackup();
        } catch (\RuntimeException) {
            // Expected.
        }

        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? $process->command : [$process->command];

            foreach ($command as $part) {
                if (is_string($part) && str_starts_with($part, '--result-file=')) {
                    $path = substr($part, strlen('--result-file='));
                    file_put_contents($path, "-- fake dump\nCREATE TABLE fake (id INT);\n");
                }
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        $backup = $service->createFullBackup();

        $this->assertSame('completed', $backup->status);
        $this->assertNotNull($backup->checksum);
        $this->assertGreaterThan(0, $backup->size_bytes);
    }

    public function test_concurrent_backup_attempt_is_rejected_while_lock_is_held(): void
    {
        config(['backup.lock_wait_timeout' => 1]);

        // Prevent any real process execution as a safety net.
        Process::fake();

        $lockPdo = $this->acquireRawLock(BackupService::LOCK_NAME);

        try {
            $service = new BackupService();

            try {
                $service->createFullBackup();
                $this->fail('Expected RuntimeException was not thrown.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('lock', strtolower($e->getMessage()));
            }

            $backup = Backup::query()->latest('id')->first();
            $this->assertNotNull($backup);
            $this->assertSame('failed', $backup->status);
            $this->assertStringContainsString('in progress', $backup->failure_reason);

            Process::assertNotRan(fn () => true);
        } finally {
            $this->releaseRawLock($lockPdo, BackupService::LOCK_NAME);
        }
    }

    public function test_database_password_is_never_present_in_process_command(): void
    {
        $markerPassword = 'S3cr3t_Marker_Password';

        config(['database.connections.mysql.password' => $markerPassword]);

        Process::fake(function (PendingProcess $process) use ($markerPassword) {
            $command = is_array($process->command) ? $process->command : [$process->command];
            $commandString = implode(' ', array_map('strval', $command));

            $this->assertStringNotContainsString($markerPassword, $commandString);

            if (property_exists($process, 'environment') && is_array($process->environment ?? null)) {
                $this->assertSame($markerPassword, $process->environment['MYSQL_PWD'] ?? null);
            }

            foreach ($command as $part) {
                if (is_string($part) && str_starts_with($part, '--result-file=')) {
                    file_put_contents(substr($part, strlen('--result-file=')), "-- fake dump\n");
                }
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        $service = new BackupService();
        $backup = $service->createFullBackup();

        $this->assertSame('completed', $backup->status);
        $this->assertStringNotContainsString($markerPassword, (string) $backup->failure_reason);
    }

    public function test_real_mysqldump_produces_a_valid_backup_when_binary_is_available(): void
    {
        $mysqldumpPath = config('backup.mysqldump_path', 'mysqldump');

        $check = Process::run([$mysqldumpPath, '--version']);

        if (!$check->successful()) {
            $this->markTestSkipped('mysqldump executable is not available in this environment.');
        }

        $service = new BackupService();
        $backup = $service->createFullBackup();

        $this->assertSame('completed', $backup->status);
        $this->assertSame('full', $backup->backup_type);
        $this->assertSame('manual', $backup->triggered_by);
        $this->assertNotNull($backup->file_path);
        $this->assertNotNull($backup->checksum);
        $this->assertGreaterThan(0, $backup->size_bytes);

        $absolutePath = Storage::disk('local')->path($backup->file_path);
        $this->assertFileExists($absolutePath);
        $this->assertSame($backup->checksum, hash_file('sha256', $absolutePath));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($absolutePath) === true);
        $this->assertSame(1, $zip->numFiles);
        $contents = $zip->getFromIndex(0);
        $zip->close();

        $this->assertNotFalse($contents);
        $this->assertStringContainsStringIgnoringCase('CREATE TABLE', $contents);
    }

    private function acquireRawLock(string $lockName): PDO
    {
        $config = config('database.connections.mysql');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $pdo = new PDO($dsn, $config['username'], $config['password']);

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?) AS result');
        $stmt->execute([$lockName, 1]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);

        if ((int) ($row->result ?? 0) !== 1) {
            $this->fail('Could not acquire the simulated external lock for the concurrency test.');
        }

        return $pdo;
    }

    private function releaseRawLock(PDO $pdo, string $lockName): void
    {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    }
}
