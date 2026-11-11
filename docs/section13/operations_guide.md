# Section 13 - Operations Guide

Nothing in this guide was executed during Phase 5 (no real backup, no restore, no
schedule:run). Commands marked "not executed" are standard but untested on this machine.

## 1. Configuration keys (config/backup.php)

None of these keys is present in .env or .env.example; defaults apply. .env.example is
intentionally unchanged (project decision).

| .env key | Default | Meaning |
|---|---|---|
| BACKUP_DISK | local | Filesystem disk for backup archives |
| BACKUP_DIRECTORY | backups | Directory on that disk |
| BACKUP_MYSQLDUMP_PATH | mysqldump | Path to mysqldump. Use the full path for a service account |
| BACKUP_MYSQL_PATH | mysql | Path to the mysql client binary (key exists in config/backup.php) |
| BACKUP_PROCESS_TIMEOUT | 300 | Seconds allowed for the dump process |
| BACKUP_LOCK_WAIT_TIMEOUT | 10 | Seconds to wait for the operation lock |
| BACKUP_RETENTION_DAYS | 30 | Value stored in retention_until. Nothing deletes expired backups |
| BACKUP_DOWNLOAD_TOKEN_TTL | 60 | Reserved. Not used by any code |
| BACKUP_SCHEDULE_ENABLED | false | Enable the daily scheduled backup |
| BACKUP_SCHEDULE_TIME | 02:00 | HH:MM time of day |
| BACKUP_SCHEDULE_TIMEZONE | UTC | Timezone in which the time is interpreted |

Checked on this machine (Phase 5.4): C:\xampp1\mysql\bin\mysqldump.exe and mysql.exe are
MariaDB 10.4.32 (mysqldump 10.19). Every option name used by BackupService (9 options)
exists in mysqldump --help, and the mysql options used by the restore command exist in
mysql --help. Option names only were compared; no dump was run.

After changing .env run: php artisan config:clear (only needed if config was cached;
bootstrap\cache\config.php did not exist during the review).

## 2. Enabling the scheduled backup

1. Set in .env: BACKUP_SCHEDULE_ENABLED=true, BACKUP_SCHEDULE_TIME=02:00,
   BACKUP_SCHEDULE_TIMEZONE=UTC, and BACKUP_MYSQLDUMP_PATH with the full path, for
   example C:\xampp1\mysql\bin\mysqldump.exe.
2. Run: php artisan schedule:list
   Expected: a line "0 2 * * * php artisan backup:run-scheduled". schedule:list shows
   times in the application timezone (UTC); add --timezone=Asia/Tehran to see local
   time (03:30 Tehran appears as 30 3 with that option). 02:00 UTC is 05:30 Tehran.
3. The event is registered with dailyAt, the configured timezone and
   withoutOverlapping(30). Invalid time or timezone: the event is not registered and a
   controlled warning is logged at most once per 6 hours (no value is printed).

## 3. The Laravel Scheduler must be started (currently it is NOT)

Findings on this machine (Phase 5.4): no Windows scheduled task contains artisan or
schedule:, and the only artisan process was php artisan serve (a development web
server, not a scheduler). Therefore nothing scheduled runs automatically. This affects
both attempts:detect-disconnected (every minute) and the scheduled backup.

Option A, Windows Task Scheduler (recommended for a server):
1. Find php: (Get-Command php).Source (here C:\xampp1\php\php.exe).
2. Open taskschd.msc, Create Task (not Basic Task).
3. General: run whether user is logged on or not.
4. Trigger: Daily, repeat every 1 minute, for a duration of Indefinitely.
5. Action: Start a program. Program: C:\xampp1\php\php.exe. Arguments:
   artisan schedule:run. Start in: E:\azmoon.
6. Settings: "Do not start a new instance" if the task is already running.
7. Verify after a few minutes: php artisan schedule:list shows "Next Due", and the
   attempts:detect-disconnected effect (or the application log) shows activity.

Option B, command line (not executed):

    schtasks /Create /TN "AzmoonScheduler" /SC MINUTE /MO 1 /TR "cmd /c cd /d E:\azmoon && C:\xampp1\php\php.exe artisan schedule:run"

Option C, development only: run php artisan schedule:work in a terminal that stays open.

The PATH of a service account can differ from the interactive PATH. Always use full
paths for php and for BACKUP_MYSQLDUMP_PATH.

## 4. Manual backup and download

Use the API (Super Admin token): POST api/v1/admin/backups to create, GET
api/v1/admin/backups to list, GET api/v1/admin/backups/{id}/download to download a
completed backup, DELETE api/v1/admin/backups/{id} to delete file and record.
Failures return a generic message; the detail is in the failure_reason column and the
BackupFailed notification goes to Super Admins.

## 5. Restore procedure (Artisan only, never executed in Phase 5)

Command: php artisan backup:restore {id} --database={exact database name}

Rules enforced by the command:
- Only the default connection; --database must equal the configured database exactly.
- Only status completed and type full, path inside the backup directory, matching
  SHA-256 checksum, well-formed archive.
- In production the command is refused when non-interactive and asks you to type the
  exact database name. There is no --force. In a local environment there is no prompt.
- A fresh safety backup is created first. If it fails, nothing is changed.
- The operation lock is taken; if another backup or restore runs, it aborts.
- The backups table rows are re-synchronised after the restore.
- An audit row is written (Backup:restored or Backup:restore_failed, user_id null,
  source artisan, no database content, no secrets).
- There is NO automatic rollback. On failure the safety backup id is printed together
  with the manual recovery command.

Before a restore: stop user traffic, note the backup id (status completed), and make sure
nobody runs a backup at the same time. Be aware that the whole database goes back to the
state of the backup, including notifications, exam attempts and anything written after
it. After a restore, verify the application and the audit log.

## 6. Troubleshooting

| Symptom | Check |
|---|---|
| Backup status failed | Read failure_reason (GET api/v1/admin/backups/{id}); it is truncated to 500 characters and MYSQL_PWD is masked |
| mysqldump not found | Set BACKUP_MYSQLDUMP_PATH to the full path; run php artisan config:clear |
| Backup fails after about 10 seconds | Another backup or restore holds the lock; retry later or raise BACKUP_LOCK_WAIT_TIMEOUT |
| Scheduled backup never runs | schedule:list shows no backup line (disabled or invalid config) or the Windows task is missing |
| Warning "backup schedule invalid" in the log | BACKUP_SCHEDULE_TIME is not HH:MM or the timezone is invalid |
| No disconnect notifications | The Scheduler is not running (section 3) |
| Backups accumulate on disk | Retention is not implemented; delete old backups through the API |

## 7. Production recommendations (not applied)

- APP_ENV=production and APP_DEBUG=false (the current .env has local and true).
- Keep the backup directory outside any web root with restricted OS permissions: the
  archives are unencrypted and contain all data.
- Copy backups off the server regularly; local disk only is a single point of failure.
- Never store secrets in Settings (values and audit rows are clear text).
- Do not keep the root files cookies*.txt or output.txt on a server (contents were not
  reviewed; the names suggest session cookies and command output).