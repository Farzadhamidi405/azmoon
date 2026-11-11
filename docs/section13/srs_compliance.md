# Section 13 - Requirements Compliance Matrix

## Basis of this document

No separate SRS document exists in the project (confirmed by the project owner).
The SRS clause comments found in code ("clause N SRS") refer to other sections
(clauses 3, 11, 14, 20-27, 33, 59.1, 60.2, 60.6). None refer to Notifications,
Backup or Settings.

Therefore this matrix is NOT a clause-by-clause SRS verification. Its basis is
the requirement list approved during the Section 13 conversation:

- Notifications: Candidate Disconnected, Candidate Reconnected, Suspicious
  Session, Exam Completed, Batch Completed, Computer Offline, Backup Completed,
  Backup Failed.
- Backup and Restore: Create, Download, Restore, Delete, Scheduled Backup;
  statuses Pending, Running, Completed, Failed.
- Settings, Scheduler, and the approved design decisions.

Legend: Done = implemented and covered by automated tests. Partial = implemented
with a documented gap. Not done = not implemented.

Test counts are the counts of test methods per file, taken from the Phase 4a
evidence (NotificationApiTest uses the #[Test] attribute, 28 tests). The full-suite
baseline from Phase 4a is 412 passed (1562 assertions). The Phase 5 re-run is
recorded in test_report.md; the Phase 6 final run (412 passed, 1566 assertions) is in test_report.md section 9.

## 1. Notifications

| Requirement | Status | Implementation | Tests | Notes |
|---|---|---|---|---|
| Candidate Disconnected | Done (needs Scheduler) | Phase 3c-1: config/attempt.php, DetectDisconnectedAttemptsCommand (attempts:detect-disconnected, scheduled every minute) | DetectDisconnectedAttemptsCommandTest (13) | Only writes connection_status and disconnected_at, never status. Runs automatically only while the Laravel Scheduler is running; no Windows Task exists (see limitations.md). |
| Candidate Reconnected | Done | Phase 3c-2: AttemptController | ReconnectedNotificationTest (10) | |
| Suspicious Session | Done | Phase 3b: AntiCheatController | SuspiciousSessionNotificationTest (10) | |
| Exam Completed | Done (manual completion only) | Phase 3d: ExamController (changeStatus to completed) | ExamCompletedNotificationTest (10) | Status completed is reached only by manual action. Body contains only the exam id. No retry for a lost notification. |
| Batch Completed | Not done | Phase 3e not implemented | none | Blocked: no clear definition. Two candidate definitions were rejected by the owner. Needs a written definition. |
| Computer Offline | Not done | Phase 3f not implemented | none | Deferred by decision. |
| Backup Completed | Done | Phase 3a: BackupService (manual) and Phase 4a (scheduled) | BackupNotificationTest (10), ScheduledBackupServiceTest (7) | Super Admins only. |
| Backup Failed | Done | Phase 3a and 4a | same as above | Super Admins only. |
| Notification infrastructure | Done | NotificationService (dispatch, fan-out, dedupe), NotificationType, custom notifications table, NotificationQueryService | NotificationServiceTest (18) | Database storage only. No Laravel database notification channel, no $user->notify(), no broadcast rows, no email. Recipients are active users whose role holds the required permission; backup types are Super Admin only. |
| Notification read API | Done | NotificationController: index, show, unread-count, mark read, read-all (5 routes) | NotificationApiTest (28) | Other users' notifications return 404. Broadcast rows cannot be marked read (409). No create/update/delete through the API. |

## 2. Backup and Restore

| Requirement | Status | Implementation | Tests | Notes |
|---|---|---|---|---|
| Create (manual) | Done | POST api/v1/admin/backups; BackupController::store; BackupService::createFullBackup | BackupApiTest (29), BackupServiceTest (5) | Super Admin only. Audit row written on success. These tests use a faked process (Phase 5 record). A real mysqldump runs only in RestoreBackupCommandTest::test_real_restore_on_dedicated_temporary_database, on a temporary database (see limitations.md L-24), never on azmoon. |
| List and show | Done | GET index (status filter, per_page 1-100), GET show | BackupApiTest | |
| Download | Done | GET api/v1/admin/backups/{backup}/download | BackupApiTest | Only status completed; realpath containment check; Audit row written before sending the file. |
| Delete | Done | DELETE api/v1/admin/backups/{backup} | BackupApiTest | Deletes file and record. If file deletion fails the record is kept. Audit row written. Status is not checked (see security_review.md). |
| Restore | Done (Artisan only) | RestoreBackupCommand | RestoreBackupCommandTest (20) | By decision there is no HTTP route for Restore. Creates a safety backup first, uses an operation lock, writes an Audit row (user_id null, source artisan). |
| Scheduled Backup | Partial | Phase 4a: config/backup.php schedule keys, BackupScheduleRegistrar, RunScheduledBackupCommand (backup:run-scheduled), routes/console.php | ScheduledBackupServiceTest (7), RunScheduledBackupCommandTest (6), BackupScheduleTest (11) | Code complete and tested. Disabled by default (BACKUP_SCHEDULE_ENABLED=false). Gaps: no Windows Task runs schedule:run; the enabled path was seen once manually via schedule:list but is not covered by an automatic end-to-end test; a real scheduled backup was never executed. |
| Status values Pending, Running, Completed, Failed | Done (schema and API) | backups.status values, IndexBackupRequest filter, BackupResource | BackupApiTest | How often BackupService persists the pending state is recorded in test_report.md (step 5.3). |
| Retention and prune | Not done | Phase 4b not implemented | none | Optional and not in the original requirement list. retention_until is recorded (default 30 days) but nothing deletes expired backups, so backups accumulate. |
| Email delivery of backups | Not done | none | none | By decision no email is sent. Columns email_sent_at and email_error exist but are unused. |

## 3. Settings

| Requirement | Status | Implementation | Tests | Notes |
|---|---|---|---|---|
| Settings API | Done (storage only) | SettingController: index, show, update (upsert by key); SettingPolicy; SettingResource; UpdateSettingRequest | SettingApiTest (16) | No delete route by design. Audit row on every write. No application code reads Setting values, so Settings do not change system behavior; backup behavior is driven by config/backup.php and .env. |
| Empty test file | Documented only | tests/Feature/SettingControllerTest.php is 0 bytes | none | Causes a PHPUnit warning and exit code 1. Not modified (out of scope). See test_report.md. |

## 4. Scheduler and operations

| Item | Status | Notes |
|---|---|---|
| Scheduled events | Done | attempts:detect-disconnected every minute. Backup event only when enabled and valid. |
| Windows Task Scheduler | Not done | No task for schedule:run exists on this machine (checked in Phase 5.0). Nothing runs automatically until a task or schedule:work is started. See operations_guide.md. |
| .env.example | Unchanged | Contains no BACKUP_ keys by decision. Keys are documented in operations_guide.md. |

## 5. Summary

- Done: Candidate Disconnected (needs the Scheduler), Candidate Reconnected,
  Suspicious Session, Exam Completed (manual completion only), Backup Completed,
  Backup Failed, the notification infrastructure and read API; manual Backup
  create, list, show, download and delete; Restore (Artisan only); the status
  values; the Settings storage API.
- Partial: Scheduled Backup (operational gaps).
- Not done: Batch Completed (3e), Computer Offline (3f), Retention (4b, optional),
  email delivery, Windows Task for the Scheduler.
- Provisional verdict: Section 13 is complete with documented exceptions. It is not
  fully matched to an SRS because no SRS text exists for this section.