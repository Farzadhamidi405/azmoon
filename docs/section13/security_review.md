# Section 13 - Security Review (read-only review, no code changed)

## Scope and method

Reviewed by reading the source of: BackupPolicy, NotificationPolicy, SettingPolicy,
User::hasPermission, BackupController, NotificationController, SettingController,
UpdateSettingRequest, IndexBackupRequest, BackupResource, SettingResource, the
Backup and Setting models, BackupService (failure sanitizing, zip creation),
RestoreBackupCommand (audit, sanitizing), NotificationService (recipient logic),
and the route middleware list. Nothing was executed against real data. Severity is
an assessment for a production deployment.

## 1. Access control

| Area | Finding |
|---|---|
| Routes | All 13 routes (5 backups, 5 notifications, 3 settings) have only the api group and auth:admin. Authorization is delegated entirely to Policies via Gate::authorize in each action. No route is public. |
| Backups | BackupPolicy allows all five abilities only when the user role name equals Super Admin. No separate permission rows exist in PermissionSeeder; this is a documented project decision. Fail-closed: a user without a role is denied. |
| Notifications | NotificationPolicy requires notifications.view; create, update and delete always return false. Ownership is enforced in the controller: another user's notification returns 404 (existence is not revealed), broadcast rows cannot be marked read (409), read-all touches only the caller's own rows. |
| Settings | SettingPolicy requires settings.view for reading and settings.update for create and update; delete is always false. |
| Super Admin | User::hasPermission returns true for Super Admin without checking the permissions table. There is no Gate::before. |
| Restore | No HTTP route. It can only be run through Artisan by someone with shell access. |

## 2. Audit trail

| Operation | Audit row |
|---|---|
| Backup create (manual, success) | Yes, action Backup:created |
| Backup download | Yes, action Backup:downloaded (written before the file is sent) |
| Backup delete | Yes, action Backup:deleted with old values |
| Restore | Yes, Backup:restored or Backup:restore_failed; no database content and no secrets; user_id and role are null |
| Setting create or update | Yes, with old and new value |
| Backup create failure (manual) | No audit row; a failed backups row and a BackupFailed notification exist |
| Scheduled backup (success or failure) | No audit row; recorded in the backups table (triggered_by scheduled, initiated_by null) and by notifications |
| Backup list and show, notification reads, setting reads | No audit row (read operations) |

## 3. Findings

| ID | Severity | Finding | Recommendation (not implemented, out of scope) |
|---|---|---|---|
| S-01 | Medium (operational) | The backup archive is a plain zip of the full database dump and is not encrypted. It holds all data including password hashes. It is stored on the local disk. | Restrict OS access to the backup directory, keep it outside any web root, and consider encrypting archives or moving them off the server. |
| S-02 | Medium (operational) | The current .env has APP_ENV=local and APP_DEBUG=true. | Set APP_DEBUG=false and APP_ENV=production on a production server. |
| S-03 | Low | Setting values are stored and written to the audit log in clear text, with no list of sensitive keys. | Never store secrets in Settings. |
| S-04 | Low | SettingController::update validates the request before authorization (FormRequest authorize returns true). A user without permission who sends invalid input gets 422 instead of 403. | Authorize before validation if strict 403 behavior is required. |
| S-05 | Low | BackupService sanitizeFailureReason hides only the pattern MYSQL_PWD=... and truncates to 500 characters. Host names or paths in mysqldump stderr are not hidden. failure_reason is visible only to Super Admins through BackupResource. The restore command also replaces the literal password. | Acceptable for Super Admin only access; extend masking if exposure widens. |
| S-06 | Low | The download path check uses str_starts_with on realpath values without a trailing separator, so a sibling directory with the same prefix would pass. file_path is set by the system, never by the client. | Append a directory separator in the comparison. |
| S-07 | Low | Backup destroy does not check status, so a pending or running backup can be deleted. The audit row is written after the deletion. | Reject deletion of running backups. |
| S-08 | Low | The 13 routes have no throttle middleware. Concurrent backups are limited by the database operation lock (a second one fails after about 10 seconds), not by rate limiting. | Add throttling on the backup routes if needed. |
| S-09 | Info | Manual backup failures and all scheduled backup events have no audit row. | Documented gap; the backups table and notifications are the record. |
| S-10 | Info | Restore audit rows have no operator identity (user_id null, source artisan). | Record the operating system user outside the application if needed. |
| S-11 | Info | The role name Super Admin is hard-coded in BackupPolicy and NotificationService. Renaming the role removes access (fail-closed). | Keep the role name stable. |
| S-12 | Info | Columns download_token, download_expires_at, email_sent_at, email_error and the config key download_token_ttl_minutes are unused. download_token is in $fillable but never written. Downloads are authenticated and audited; there are no signed URLs and no email. | Remove or implement in a future phase. |
| S-13 | Info | BackupResource exposes storage_location to Super Admins. Its content was not re-checked in this review. | Verify before widening access. |

## 4. Positive controls observed

- The MySQL password is passed through the MYSQL_PWD environment variable, never on
  the command line (BackupService and RestoreBackupCommand).
- Manual create returns a generic message on failure; the raw error stays in the
  failure_reason column (truncated, MYSQL_PWD masked).
- Download is restricted to completed backups, uses a realpath containment check, and
  does not expose file_path in the API resource.
- Delete keeps the database record if the file cannot be removed.
- IndexBackupRequest limits status to the four valid values and per_page to 1-100.
- Setting keys must match a lowercase dotted pattern (max 255 characters).
- Notification fan-out uses active users only; backup notifications go to Super
  Admins only.
- Restore creates a safety backup first and holds an operation lock.

## 5. Not verified in this review

- Whether inactive or locked staff accounts can authenticate through the admin guard.
- Behavior of the real mysqldump and mysql binaries on the real database azmoon (tests use fakes, except one restore test on a temporary database; see limitations.md L-24).
- HTTPS, CORS, session and token lifetime settings.
- Setting value length limits at the database level.

## 6. Test evidence for authorization

Counts below are lines containing 401 or 403 assertions, not test counts:
BackupApiTest 10, SettingApiTest 6, NotificationApiTest 10.