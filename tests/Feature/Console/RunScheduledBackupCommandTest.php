<?php

namespace Tests\Feature\Console;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RunScheduledBackupCommandTest extends TestCase
{
    private function fakeBackup(int $id = 42, string $filePath = 'backups/example.zip'): Backup
    {
        $backup = new Backup();
        $backup->forceFill([
            'id' => $id,
            'status' => 'completed',
            'triggered_by' => 'scheduled',
            'file_path' => $filePath,
        ]);

        return $backup;
    }

    public function test_command_runs_a_scheduled_backup_without_initiator_and_exits_zero(): void
    {
        $backup = $this->fakeBackup(42);

        $this->mock(BackupService::class, function (MockInterface $mock) use ($backup) {
            $mock->shouldReceive('createFullBackup')
                ->once()
                ->withArgs(fn ($initiatedBy, $trigger) => $initiatedBy === null && $trigger === 'scheduled')
                ->andReturn($backup);
        });

        $exit = Artisan::call('backup:run-scheduled');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Scheduled backup completed', $output);
        $this->assertStringContainsString('#42', $output);
    }

    public function test_successful_output_does_not_print_paths_or_details(): void
    {
        $backup = $this->fakeBackup(7, 'backups/very_secret_dir/secret_file.zip');

        $this->mock(BackupService::class, function (MockInterface $mock) use ($backup) {
            $mock->shouldReceive('createFullBackup')->once()->andReturn($backup);
        });

        $exit = Artisan::call('backup:run-scheduled');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('very_secret_dir', $output);
        $this->assertStringNotContainsString('secret_file', $output);
        $this->assertStringNotContainsString('.zip', $output);
        $this->assertStringNotContainsString('sha256', strtolower($output));
    }

    public function test_failure_exits_one_reports_the_exception_and_hides_details(): void
    {
        Exceptions::fake();

        $this->mock(BackupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createFullBackup')
                ->once()
                ->andThrow(new RuntimeException('mysqldump failed: SECRET_TOKEN_123 at C:\secret\dump.sql'));
        });

        $exit = Artisan::call('backup:run-scheduled');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Scheduled backup failed', $output);
        $this->assertStringNotContainsString('SECRET_TOKEN_123', $output);
        $this->assertStringNotContainsString('dump.sql', $output);
        $this->assertStringNotContainsString('mysqldump', $output);
        $this->assertStringNotContainsString('C:\\', $output);

        Exceptions::assertReported(
            fn (RuntimeException $e) => str_contains($e->getMessage(), 'SECRET_TOKEN_123')
        );
    }

    public function test_non_runtime_exceptions_also_exit_one_and_are_reported(): void
    {
        Exceptions::fake();

        $this->mock(BackupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createFullBackup')
                ->once()
                ->andThrow(new InvalidArgumentException('Invalid backup trigger.'));
        });

        $exit = Artisan::call('backup:run-scheduled');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Scheduled backup failed', $output);
        $this->assertStringNotContainsString('Invalid backup trigger', $output);

        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function test_manual_run_does_not_depend_on_schedule_enabled(): void
    {
        config(['backup.schedule_enabled' => false]);

        $backup = $this->fakeBackup(9);

        $this->mock(BackupService::class, function (MockInterface $mock) use ($backup) {
            $mock->shouldReceive('createFullBackup')
                ->once()
                ->withArgs(fn ($initiatedBy, $trigger) => $initiatedBy === null && $trigger === 'scheduled')
                ->andReturn($backup);
        });

        $this->assertFalse((bool) config('backup.schedule_enabled'));
        $this->assertSame(0, Artisan::call('backup:run-scheduled'));
    }

    public function test_command_is_registered_in_artisan(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('backup:run-scheduled', $commands);
        $this->assertSame([], array_keys($commands['backup:run-scheduled']->getDefinition()->getArguments()));
    }
}