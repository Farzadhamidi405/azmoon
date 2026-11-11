<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Restore a completed full backup into the application's default database.
 *
 * Safety rules (all enforced before any destructive step):
 *  - only the default connection is allowed, and --database must exactly equal its configured database;
 *  - only status=completed, backup_type=full backups, with a path inside the backup directory,
 *    a matching SHA-256 checksum and a well-formed archive;
 *  - in production the operator must type the exact database name (there is deliberately NO --force);
 *  - a fresh safety backup is taken first; if it fails nothing is touched.
 *
 * There is NO automatic rollback. On failure the safety backup is reported for manual recovery.
 */
class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore
        {backup : ID of the completed full backup to restore}
        {--database= : Exact name of the target database (required; must equal the configured database of the default connection)}
        {--connection= : Database connection (must be the default connection)}';

    protected $description = 'Restore a completed full backup into the default database (Artisan only, never exposed via API)';

    public function handle(): int
    {
        // ── 1. Target validation (before any backup or restore work) ──────────
        $targetDatabase = (string) $this->option('database');

        if ($targetDatabase === '') {
            $this->error('The --database option is required and must be the exact target database name.');

            return self::FAILURE;
        }

        $defaultConnection = (string) config('database.default');
        $connectionName = (string) ($this->option('connection') ?: $defaultConnection);

        if ($connectionName !== $defaultConnection) {
            $this->error('Restore is only allowed on the default database connection.');
            $this->line('No changes were made.');

            return self::FAILURE;
        }

        $connectionConfig = config("database.connections.{$connectionName}");

        if (!is_array($connectionConfig) || !in_array($connectionConfig['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Restore is only supported for MySQL/MariaDB connections.');
            $this->line('No changes were made.');

            return self::FAILURE;
        }

        $configuredDatabase = (string) ($connectionConfig['database'] ?? '');

        if ($configuredDatabase === '' || !hash_equals($configuredDatabase, $targetDatabase)) {
            $this->error('The --database value does not exactly match the database configured for this connection.');
            $this->line('No changes were made.');

            return self::FAILURE;
        }

        // ── 2. Backup validation (read-only) ──────────────────────────────────
        $idArgument = (string) $this->argument('backup');

        try {
            if (!ctype_digit($idArgument)) {
                throw new RuntimeException('The backup argument must be a numeric ID.');
            }

            $backup = $this->loadRestorableBackup((int) $idArgument);
            $zipPath = $this->resolveArchivePath($backup);
            $this->verifyChecksum($backup, $zipPath);
            $entryName = $this->inspectArchive($zipPath);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('No changes were made.');

            return self::FAILURE;
        }

        // ── 3. Production confirmation (cannot be bypassed) ───────────────────
        if ($this->laravel->environment('production')) {
            if (!$this->input->isInteractive()) {
                $this->error('Restore in production requires interactive confirmation; non-interactive mode is refused.');
                $this->line('No changes were made.');

                return self::FAILURE;
            }

            $this->warn('PRODUCTION ENVIRONMENT: this will overwrite the contents of the target database.');
            $answer = $this->ask('Type the exact database name to confirm the restore');

            if (!is_string($answer) || !hash_equals($targetDatabase, $answer)) {
                $this->error('Confirmation did not match. Restore aborted.');
                $this->line('No changes were made.');

                return self::FAILURE;
            }
        }

        // ── 4. Safety backup (failure => nothing destructive happens) ─────────
        try {
            $safety = app(BackupService::class)->createFullBackup();
        } catch (Throwable $e) {
            $this->error('Safety backup failed; restore aborted. ' . $e->getMessage());
            $this->line('No changes were made to the target database.');

            return self::FAILURE;
        }

        if ($safety->status !== 'completed' || empty($safety->file_path)) {
            $this->error('Safety backup did not complete; restore aborted.');
            $this->line('No changes were made to the target database.');

            return self::FAILURE;
        }

        $this->info("Safety backup #{$safety->id} created: {$safety->file_path}");

        // ── 5. Extract SQL to a temp file (still non-destructive) ─────────────
        $sqlPath = null;

        try {
            $sqlPath = $this->extractSql($zipPath, $entryName);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('No changes were made to the target database.');

            return self::FAILURE;
        }

        // ── 6. Lock on the SAME connection used for release ───────────────────
        $connection = DB::connection($connectionName);

        try {
            $lockTimeout = (int) config('backup.lock_wait_timeout', 10);
            $row = $connection->select('SELECT GET_LOCK(?, ?) AS lock_result', [BackupService::LOCK_NAME, $lockTimeout]);
            $locked = (int) ($row[0]->lock_result ?? 0) === 1;
        } catch (Throwable) {
            $locked = false;
        }

        if (!$locked) {
            $this->cleanupFile($sqlPath);
            $this->error('Could not acquire the operation lock: another backup or restore is in progress.');
            $this->line('No changes were made to the target database.');

            return self::FAILURE;
        }

        // ── 7. Destructive stage ──────────────────────────────────────────────
        $password = (string) ($connectionConfig['password'] ?? '');
        $registry = null;
        $errorMessage = null;
        $registryRestored = true;

        try {
            $registry = $connection->table('backups')->get()->map(fn ($row) => (array) $row)->all();

            $this->runMysql($connectionConfig, $targetDatabase, $sqlPath);
        } catch (Throwable $e) {
            $errorMessage = $this->sanitize($e->getMessage(), $password);
        } finally {
            if ($registry !== null) {
                $registryRestored = $this->resyncBackupRegistry($connection, $registry);
            }

            $this->releaseLock($connection);
            $this->cleanupFile($sqlPath);
        }

        $succeeded = $errorMessage === null;

        // ── 8. Audit log (no database content, no secrets) ────────────────────
        $auditWritten = $this->writeAudit($backup, $safety, $targetDatabase, $succeeded);

        // ── 9. Honest report ──────────────────────────────────────────────────
        if (!$registryRestored) {
            $this->warn('The backup registry could not be re-synchronised; verify the backups table manually.');
        }

        if (!$auditWritten) {
            $this->warn('The audit log entry could not be written. Record this restore manually.');
        }

        if ($succeeded) {
            $this->info("Backup #{$backup->id} was restored into the target database.");
            $this->line("Pre-restore safety backup: #{$safety->id} ({$safety->file_path})");

            return self::SUCCESS;
        }

        $this->error('Restore FAILED: ' . $errorMessage);
        $this->error('The target database may be partially restored or inconsistent. No automatic rollback was performed.');
        $this->line("A safety backup of the pre-restore state exists: #{$safety->id} ({$safety->file_path})");
        $this->line("Manual recovery: php artisan backup:restore {$safety->id} --database={$targetDatabase}");

        return self::FAILURE;
    }

    private function loadRestorableBackup(int $id): Backup
    {
        $backup = Backup::query()->find($id);

        if (!$backup) {
            throw new RuntimeException("Backup #{$id} was not found.");
        }

        if ($backup->status !== 'completed') {
            throw new RuntimeException('Only backups with status "completed" can be restored.');
        }

        if ($backup->backup_type !== 'full') {
            throw new RuntimeException('Only full backups can be restored.');
        }

        return $backup;
    }

    private function resolveArchivePath(Backup $backup): string
    {
        $disk = (string) config('backup.disk', 'local');
        $directory = trim((string) config('backup.directory', 'backups'), '/');
        $relative = (string) $backup->file_path;

        if (!empty($backup->storage_location) && $backup->storage_location !== $disk) {
            throw new RuntimeException('The backup is not stored on the configured backup disk.');
        }

        if ($relative === '' || str_contains($relative, "\0") || !str_starts_with($relative, $directory . '/')) {
            throw new RuntimeException('The backup file path is invalid or outside the allowed backup directory.');
        }

        $storage = Storage::disk($disk);
        $realBase = realpath($storage->path($directory));
        $realFile = realpath($storage->path($relative));

        if ($realBase === false || $realFile === false) {
            throw new RuntimeException('The backup file was not found on disk.');
        }

        $prefix = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!str_starts_with($realFile, $prefix)
            || !is_file($realFile)
            || strtolower(pathinfo($realFile, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('The backup file path is invalid or outside the allowed backup directory.');
        }

        return $realFile;
    }

    private function verifyChecksum(Backup $backup, string $zipPath): void
    {
        $expected = strtolower((string) $backup->checksum);
        $actual = hash_file('sha256', $zipPath);

        if ($expected === '' || $actual === false || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Checksum verification failed: the backup file is corrupted or was modified.');
        }
    }

    /**
     * The archive must contain exactly one flat *.sql entry.
     */
    private function inspectArchive(string $zipPath): string
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The backup archive could not be opened.');
        }

        try {
            if ($zip->numFiles !== 1) {
                throw new RuntimeException('The backup archive has an unexpected structure.');
            }

            $name = (string) $zip->getNameIndex(0);
            $stat = $zip->statIndex(0);

            if ($name === ''
                || $name !== basename($name)
                || str_contains($name, '\\')
                || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'sql'
                || !is_array($stat)
                || ($stat['size'] ?? 0) <= 0) {
                throw new RuntimeException('The backup archive has an unexpected structure.');
            }

            return $name;
        } finally {
            $zip->close();
        }
    }

    private function extractSql(string $zipPath, string $entryName): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'azmoon_restore_');

        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file for the restore.');
        }

        $zip = new ZipArchive();
        $in = false;
        $out = false;

        try {
            if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('The backup archive could not be opened.');
            }

            $in = $zip->getStream($entryName);
            $out = fopen($tmp, 'wb');

            if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException('Failed to extract the SQL dump from the archive.');
            }
        } catch (Throwable $e) {
            $this->cleanupFile($tmp);

            throw $e instanceof RuntimeException ? $e : new RuntimeException('Failed to extract the SQL dump from the archive.');
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            $zip->close();
        }

        if (!is_file($tmp) || filesize($tmp) === 0) {
            $this->cleanupFile($tmp);

            throw new RuntimeException('The extracted SQL dump is empty.');
        }

        return $tmp;
    }

    private function runMysql(array $connectionConfig, string $database, string $sqlPath): void
    {
        $command = [
            (string) config('backup.mysql_path', 'mysql'),
            '--host=' . ($connectionConfig['host'] ?? '127.0.0.1'),
            '--port=' . ($connectionConfig['port'] ?? 3306),
            '--user=' . ($connectionConfig['username'] ?? ''),
            '--protocol=TCP',
            '--default-character-set=utf8mb4',
            $database,
        ];

        $password = (string) ($connectionConfig['password'] ?? '');

        $handle = fopen($sqlPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the extracted SQL dump.');
        }

        try {
            $result = Process::timeout((int) config('backup.process_timeout', 300))
                ->env(['MYSQL_PWD' => $password])
                ->input($handle)
                ->run($command);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (!$result->successful()) {
            throw new RuntimeException(
                'mysql exited with code ' . $result->exitCode() . ': ' . $this->sanitize($result->errorOutput(), $password)
            );
        }
    }

    /**
     * Restoring overwrites the backups table with its state at dump time. Put the registry back
     * exactly as it was before the restore so that no backup file becomes untracked.
     */
    private function resyncBackupRegistry(Connection $connection, array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        try {
            $ids = array_map(fn (array $row) => $row['id'], $rows);

            $connection->table('backups')->whereNotIn('id', $ids)->delete();

            $columns = array_values(array_diff(array_keys($rows[0]), ['id']));

            foreach (array_chunk($rows, 100) as $chunk) {
                $connection->table('backups')->upsert($chunk, ['id'], $columns);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function releaseLock(Connection $connection): void
    {
        try {
            $connection->select('SELECT RELEASE_LOCK(?) AS lock_result', [BackupService::LOCK_NAME]);
        } catch (Throwable) {
            $this->warn('The operation lock could not be released explicitly; it is released when the connection closes.');
        }
    }

    private function writeAudit(Backup $backup, Backup $safety, string $database, bool $succeeded): bool
    {
        try {
            AuditLog::create([
                'user_id'     => null,
                'role'        => null,
                'action'      => $succeeded ? 'Backup:restored' : 'Backup:restore_failed',
                'module'      => 'backups',
                'target_type' => 'Backup',
                'target_id'   => $backup->id,
                'old_value'   => null,
                'new_value'   => [
                    'status'           => $succeeded ? 'success' : 'failed',
                    'database'         => $database,
                    'safety_backup_id' => $safety->id,
                    'source'           => 'artisan',
                ],
                'ip_address'  => null,
                'user_agent'  => null,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cleanupFile(?string $path): void
    {
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    private function sanitize(string $message, string $password = ''): string
    {
        $message = preg_replace('/MYSQL_PWD=\S*/i', 'MYSQL_PWD=[hidden]', $message) ?? $message;

        if ($password !== '') {
            $message = str_replace($password, '[hidden]', $message);
        }

        return Str::limit($message, 500, '...');
    }
}