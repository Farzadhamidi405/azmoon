<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RunScheduledBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:run-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a scheduled full database backup (system-initiated, no user).';

    /**
     * Run a full backup as a scheduled (system) backup.
     *
     * Notifications (Backup Completed / Backup Failed) are sent by BackupService only.
     * This command never prints paths, process output or credentials.
     */
    public function handle(BackupService $backupService): int
    {
        try {
            $backup = $backupService->createFullBackup(null, BackupService::TRIGGER_SCHEDULED);
        } catch (Throwable $e) {
            report($e);

            $this->error('Scheduled backup failed. See the application log and the backups list for details.');

            return self::FAILURE;
        }

        $this->info('Scheduled backup completed (backup #' . $backup->id . ').');

        return self::SUCCESS;
    }
}