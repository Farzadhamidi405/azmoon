<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    public const LOCK_NAME = 'azmoon_backup_operation';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';

    public function __construct(private ?NotificationService $notificationService = null)
    {
    }

    /**
     * Create a full database backup (manual or scheduled).
     *
     * Notifications (Backup Completed / Backup Failed) are sent AFTER the operation lock
     * has been released, and a notification failure never changes the outcome of the backup.
     *
     * @throws RuntimeException on lock contention or backup failure.
     * @throws InvalidArgumentException on an invalid trigger, or a scheduled backup with an initiating user.
     */
    public function createFullBackup(?int $initiatedBy = null, string $trigger = self::TRIGGER_MANUAL): Backup
    {
        if (!in_array($trigger, [self::TRIGGER_MANUAL, self::TRIGGER_SCHEDULED], true)) {
            throw new InvalidArgumentException('Invalid backup trigger. Allowed values: manual, scheduled.');
        }

        if ($trigger === self::TRIGGER_SCHEDULED && $initiatedBy !== null) {
            throw new InvalidArgumentException('A scheduled backup cannot have an initiating user.');
        }

        $connectionName = config('database.default');
        $connectionConfig = config("database.connections.{$connectionName}");

        if (!is_array($connectionConfig) || !in_array($connectionConfig['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Backup is only supported for MySQL/MariaDB connections.');
        }

        $backup = Backup::create([
            'backup_type' => 'full',
            'status' => 'pending',
            'triggered_by' => $trigger,
            'initiated_by' => $initiatedBy,
        ]);

        if (!$this->acquireLock()) {
            $backup->update([
                'status' => 'failed',
                'failure_reason' => 'Another backup or restore operation is currently in progress.',
                'completed_at' => now(),
            ]);

            // No lock is held on this path, so the notification can be sent right away.
            $this->notifyBackupFailed($backup);

            throw new RuntimeException('Could not acquire backup lock: another operation is in progress.');
        }

        $failure = null;

        try {
            $backup->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

            $this->runFullBackup($backup, $connectionConfig);
        } catch (Throwable $e) {
            $failure = $e;
        } finally {
            $this->releaseLock();
        }

        if ($failure !== null) {
            $this->notifyBackupFailed($backup);

            throw $failure;
        }

        $backup = $backup->fresh();

        $this->notifyBackupCompleted($backup);

        return $backup;
    }

    private function acquireLock(): bool
    {
        $timeout = (int) config('backup.lock_wait_timeout', 10);

        $row = DB::select('SELECT GET_LOCK(?, ?) AS lock_result', [self::LOCK_NAME, $timeout]);

        return (int) ($row[0]->lock_result ?? 0) === 1;
    }

    private function releaseLock(): void
    {
        DB::select('SELECT RELEASE_LOCK(?) AS lock_result', [self::LOCK_NAME]);
    }

    private function runFullBackup(Backup $backup, array $connectionConfig): void
    {
        $disk = config('backup.disk', 'local');
        $directory = trim((string) config('backup.directory', 'backups'), '/');

        Storage::disk($disk)->makeDirectory($directory);

        $baseName = 'backup_' . now()->format('Ymd_His') . '_' . $backup->id;
        $sqlRelativePath = $directory . '/' . $baseName . '.sql';
        $zipRelativePath = $directory . '/' . $baseName . '.zip';

        $sqlAbsolutePath = Storage::disk($disk)->path($sqlRelativePath);
        $zipAbsolutePath = Storage::disk($disk)->path($zipRelativePath);

        try {
            $this->dumpDatabase($connectionConfig, $sqlAbsolutePath);

            if (!is_file($sqlAbsolutePath) || filesize($sqlAbsolutePath) === 0) {
                throw new RuntimeException('Database dump produced no output.');
            }

            $this->zipFile($sqlAbsolutePath, $zipAbsolutePath, $baseName . '.sql');

            $checksum = hash_file('sha256', $zipAbsolutePath);
            $size = filesize($zipAbsolutePath);

            if ($checksum === false || $size === false) {
                throw new RuntimeException('Failed to finalize backup archive.');
            }

            $backup->update([
                'file_path' => $zipRelativePath,
                'size_bytes' => $size,
                'checksum' => $checksum,
                'storage_location' => $disk,
                'status' => 'completed',
                'completed_at' => now(),
                'retention_until' => now()->addDays((int) config('backup.retention_days', 30)),
            ]);
        } catch (Throwable $e) {
            $this->cleanupFile($zipAbsolutePath);

            $reason = $this->sanitizeFailureReason($e->getMessage());

            $backup->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'completed_at' => now(),
            ]);

            throw new RuntimeException('Backup failed: ' . $reason, previous: $e);
        } finally {
            $this->cleanupFile($sqlAbsolutePath);
        }
    }

    private function dumpDatabase(array $connectionConfig, string $destinationPath): void
    {
        $mysqldumpPath = config('backup.mysqldump_path', 'mysqldump');

        $command = [
            $mysqldumpPath,
            '--host=' . ($connectionConfig['host'] ?? '127.0.0.1'),
            '--port=' . ($connectionConfig['port'] ?? 3306),
            '--user=' . ($connectionConfig['username'] ?? ''),
            '--protocol=TCP',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--result-file=' . $destinationPath,
            (string) ($connectionConfig['database'] ?? ''),
        ];

        $password = (string) ($connectionConfig['password'] ?? '');

        $result = Process::timeout((int) config('backup.process_timeout', 300))
            ->env(['MYSQL_PWD' => $password])
            ->run($command);

        if (!$result->successful()) {
            throw new RuntimeException(
                'mysqldump exited with code ' . $result->exitCode() . ': ' . $this->sanitizeFailureReason($result->errorOutput())
            );
        }
    }

    private function zipFile(string $sourcePath, string $destinationPath, string $entryName): void
    {
        $zip = new ZipArchive();

        if ($zip->open($destinationPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create backup archive.');
        }

        $zip->addFile($sourcePath, $entryName);
        $zip->close();

        if (!is_file($destinationPath)) {
            throw new RuntimeException('Backup archive was not created.');
        }
    }

    private function cleanupFile(?string $path): void
    {
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    private function sanitizeFailureReason(string $message): string
    {
        $message = preg_replace('/MYSQL_PWD=\S*/i', 'MYSQL_PWD=[hidden]', $message) ?? $message;

        return Str::limit($message, 500, '...');
    }

    private function notifyBackupCompleted(Backup $backup): void
    {
        $this->sendNotification(
            NotificationType::BackupCompleted,
            'نسخه پشتیبان با موفقیت ایجاد شد',
            'نسخه پشتیبان شماره ' . $backup->id . ' با موفقیت ایجاد شد.',
            $backup
        );
    }

    private function notifyBackupFailed(Backup $backup): void
    {
        $this->sendNotification(
            NotificationType::BackupFailed,
            'ایجاد نسخه پشتیبان ناموفق بود',
            'ایجاد نسخه پشتیبان شماره ' . $backup->id . ' با خطا مواجه شد. جزئیات در سابقه نسخه پشتیبان ثبت شده است.',
            $backup
        );
    }

    /**
     * A notification problem must never change the outcome of a backup:
     * it is only reported (logged), never thrown.
     */
    private function sendNotification(NotificationType $type, string $title, string $body, Backup $backup): void
    {
        try {
            $this->notifications()->dispatch($type, $title, $body, 'backup', $backup->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notifications(): NotificationService
    {
        return $this->notificationService ??= new NotificationService();
    }
}