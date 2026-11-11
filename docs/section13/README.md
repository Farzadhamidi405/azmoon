# Section 13 - Notifications, Backup and Settings

Status: complete with documented exceptions. See srs_compliance.md and limitations.md.

## Documents in this folder

| File | Content |
|---|---|
| README.md | This file: architecture and feature list |
| srs_compliance.md | Requirements compliance matrix (Done, Partial, Not done) |
| security_review.md | Read-only security review and findings |
| operations_guide.md | .env keys, Windows Task Scheduler, Restore procedure, troubleshooting |
| test_report.md | Real test results, exit codes, the empty test file, the Phase 6 investigation of the intermittent failures |
| limitations.md | Known limitations and items not executed |

## 1. Notifications

Storage is a custom notifications table (columns: type, title, body, related_type,
related_id, is_read, notifiable_user_id). It is NOT the Laravel database notification
channel. $user->notify() is never used. Email is never sent. No broadcast rows are
created: every row has a notifiable_user_id (fan-out per recipient).

Main classes:
- NotificationType (enum): CandidateDisconnected, CandidateReconnected,
  SuspiciousSession, ExamCompleted, BackupCompleted, BackupFailed.
  superAdminsOnly() is true for the two Backup types.
- App\Services\NotificationService: dispatch with fan-out and dedupe. Recipients are
  active users whose role holds the permissions required by the type, plus Super Admins.
  Backup notifications go to Super Admins only.
- App\Services\NotificationQueryService: listing, unread count, mark all own as read.
- App\Models\Notification, NotificationPolicy, NotificationResource,
  App\Http\Controllers\Api\V1\NotificationController.

Where each notification is raised:

| Type | Raised by | Phase |
|---|---|---|
| Backup Completed, Backup Failed | App\Services\BackupService (manual and scheduled) | 3a, 4a |
| Suspicious Session | AntiCheatController | 3b |
| Candidate Disconnected | DetectDisconnectedAttemptsCommand (attempts:detect-disconnected), settings in config/attempt.php | 3c-1 |
| Candidate Reconnected | AttemptController | 3c-2 |
| Exam Completed | ExamController (status changed to completed) | 3d |
| Batch Completed | not implemented (3e, blocked) | - |
| Computer Offline | not implemented (3f, deferred) | - |

A notification problem never changes the outcome of the operation that raised it: it is
reported to the log and swallowed (for example in BackupService).

## 2. Backup and Restore

Main classes and files:
- App\Services\BackupService: createFullBackup(?int $initiatedBy, string $trigger).
  Runs mysqldump, zips the dump, stores it on the configured disk, records size and
  checksum, and raises the notification. The MySQL password is passed only through the
  MYSQL_PWD environment variable. An operation lock (GET_LOCK, name
  azmoon_backup_operation, wait time from backup.lock_wait_timeout) prevents concurrent
  backup and restore.
- App\Http\Controllers\Api\V1\BackupController, BackupPolicy (Super Admin only),
  BackupResource, IndexBackupRequest, App\Models\Backup.
- App\Console\Commands\RestoreBackupCommand (backup:restore): Artisan only.
- App\Console\Commands\RunScheduledBackupCommand (backup:run-scheduled).
- App\Console\BackupScheduleRegistrar and routes/console.php: register the daily backup
  event from config.
- config/backup.php: all backup keys (see operations_guide.md).

Status values of the backups table: pending, running, completed, failed.
Trigger values: manual, scheduled. Backup type: full (incremental exists in the schema
but is not produced).

## 3. Settings

Key and value store: SettingController (index, show, update as upsert by key),
SettingPolicy (settings.view, settings.update), SettingResource, UpdateSettingRequest,
App\Models\Setting. No delete route. No application code reads Setting values; they do
not change system behavior.

## 4. Routes (all use the api group and auth:admin)

| Method | Path | Action |
|---|---|---|
| GET | api/v1/admin/backups | index |
| POST | api/v1/admin/backups | store |
| GET | api/v1/admin/backups/{backup} | show |
| DELETE | api/v1/admin/backups/{backup} | destroy |
| GET | api/v1/admin/backups/{backup}/download | download |
| GET | api/v1/admin/notifications | index |
| PATCH | api/v1/admin/notifications/read-all | readAll |
| GET | api/v1/admin/notifications/unread-count | unreadCount |
| GET | api/v1/admin/notifications/{notification} | show |
| PATCH | api/v1/admin/notifications/{notification}/read | markRead |
| GET | api/v1/admin/settings | index |
| GET | api/v1/admin/settings/{key} | show |
| PUT | api/v1/admin/settings/{key} | update |

There is deliberately no HTTP route for Restore.

## 5. Artisan commands

| Command | Purpose |
|---|---|
| attempts:detect-disconnected | Mark in-progress attempts with a stale heartbeat as disconnected and notify (scheduled every minute) |
| backup:run-scheduled | Create a scheduled full backup (system-initiated, no user). Independent of backup.schedule_enabled |
| backup:restore {backup} --database= --connection= | Restore a completed full backup into the default database |

## 6. Approved design decisions

- Notifications are stored in the database only. No email, no broadcast.
- Backups are stored on the local disk only.
- Restore is Artisan only, with no --force option.
- Everything is synchronous (no queue jobs).
- Retention and prune (4b) is separate and optional, and is not implemented.
- The scheduled backup is disabled by default.
- status='disconnected' is never written; only connection_status and disconnected_at.

## 7. Tests

Section 13 test files (193 tests in total, see test_report.md for real results):
BackupApiTest, SettingApiTest, NotificationApiTest, BackupServiceTest,
ScheduledBackupServiceTest, BackupNotificationTest, RestoreBackupCommandTest,
RunScheduledBackupCommandTest, BackupScheduleTest, DetectDisconnectedAttemptsCommandTest,
NotificationServiceTest, SuspiciousSessionNotificationTest, ReconnectedNotificationTest,
ExamCompletedNotificationTest. All tests run against the azmoon_test database. Phase 5 records
the tests as using a faked process, except RestoreBackupCommandTest::test_real_restore_on_dedicated_temporary_database, which runs a real mysqldump and mysql restore on a dedicated temporary database (see limitations.md L-24). A real mysqldump or restore was never executed against the real database azmoon.