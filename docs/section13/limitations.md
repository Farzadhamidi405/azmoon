# Section 13 - Limitations and Items Not Executed

## A. Not implemented

| ID | Item | Reason |
|---|---|---|
| L-01 | Batch Completed notification (3e) | Blocked: no clear definition. Two candidate definitions were rejected |
| L-02 | Computer Offline notification (3f) | Deferred by decision |
| L-03 | Retention and prune (4b) | Optional, needs separate approval. retention_until is stored but nothing deletes old backups, so backups accumulate |
| L-04 | Email delivery of backups | Excluded by decision. Columns email_sent_at and email_error are unused |
| L-05 | Automatic Scheduler | No Windows task and no schedule:work. attempts:detect-disconnected and the scheduled backup do not run by themselves |
| L-06 | Restore over HTTP | Excluded by decision (Artisan only) |

## B. Operational and verification gaps

| ID | Limitation |
|---|---|
| L-07 | A real mysqldump and a real restore were never executed against the real database azmoon. They run only in RestoreBackupCommandTest::test_real_restore_on_dedicated_temporary_database, on a dedicated temporary database (see L-24 for one timeout). Option names were checked against MariaDB 10.4.32. Dump and restore behavior on the real database on XAMPP is unproven |
| L-08 | The enabled schedule path is not covered by an automatic end-to-end test; it was seen once through schedule:list |
| L-09 | The invalid-schedule warning was tested only with the array cache, not the database cache |
| L-10 | A scheduled backup that runs while a manual backup or a restore holds the lock fails after about 10 seconds and raises a BackupFailed notification |
| L-11 | A driver other than MySQL or MariaDB fails before a row is created; for a scheduled run only the log and exit code show it |
| L-12 | The PATH of a service account may differ from the interactive PATH; use full paths |
| L-13 | Restore returns the whole database, including notifications, to the backup time |
| L-14 | A restore has no automatic rollback; the safety backup is for manual recovery |

## C. Notification behavior

| ID | Limitation |
|---|---|
| L-15 | Exam status completed is reached only by a manual action |
| L-16 | changeStatus has no transaction or lock |
| L-17 | A lost notification is not retried; dedupe has no lock |
| L-18 | The Exam Completed body contains only the exam id |
| L-19 | Service errors propagate; the tests do not compare exact Persian text |

## D. Security items (details in security_review.md)

Unencrypted archives (S-01), APP_DEBUG=true in the current .env (S-02), clear-text
Settings (S-03), validation before authorization in Settings update (S-04), partial
masking of failure reasons (S-05), prefix check without separator (S-06), delete of a
running backup allowed (S-07), no throttling (S-08), no audit for manual failures and
scheduled runs (S-09), no operator identity for restore (S-10), hard-coded role name
(S-11), unused download and email columns and config key (S-12).

## E. Test suite

| ID | Limitation |
|---|---|
| L-20 | tests/Feature/SettingControllerTest.php is empty (0 bytes): PHPUnit warning and exit code 1 on every run that scans it. Not changed |
| L-21 | AntiCheatTest line 188 (risk_score_increases_with_more_events) failed intermittently: 1 of 30 controlled runs in Phase 6.1 and 1 of 100 in the Phase 6.3 diagnostic run. Cause: the test query latest('computed_at') had no tie-break, and risk score rows written in the same second have equal computed_at. H1 (same-second ties) was confirmed by the diagnostic run: 3 rows were tied and the query returned id 3 (score 9) instead of id 5 (score 15). H2 (a lost POST or row) was rejected: every POST returned 200 and all 5 events and 5 rows were stored in all 100 runs. The query with latest('id') returned the correct score in 100 of 100 diagnostic runs. Phase 6.4 added latest('id') and assertOk() to the test only (11 runs after the change passed; this does not prove the failure cannot recur). No product code was changed or needed. Still unknown: the internal MariaDB mechanism that picks one of several rows with equal computed_at. The behavior of riskScore() in product code was not confirmed by an independent run |
| L-22 | The artisan and phpunit assertion totals differed by one in Phase 5 (artisan 1561, phpunit 1562); the cause was not investigated and is still unexplained. Phase 6.0 to 6.3 found no link to L-21. After the Phase 6.4 change both runners reported 1566 in the final check. artisan 1561 + 5 new assertOk = 1566 matches, but phpunit 1562 + 5 would be 1567, so the earlier difference was not reproduced and not explained. Not claimed as resolved |
| L-23 | Two PSR-4 autoload warnings outside Section 13 |
| L-24 | RestoreBackupCommandTest::test_real_restore_on_dedicated_temporary_database (line 461) failed once in the full phpunit run of Phase 6.4 (Tests: 412, Assertions: 1558, Failures: 1, Time 03:26:24.441): the safety backup mysqldump on the temporary database exceeded the 300 second process timeout (backup.process_timeout). The run overlapped a Windows connected standby of about 3h25m (System log: event 506 at 14:13:38, event 507 at 17:39:17). Three independent runs of the same test in Phase 6.5 passed (exit 0, 1 passed, 28 assertions, about 15.3s each), and both full-suite runs of the final check (artisan and phpunit, 412 tests, 1566 assertions) passed with no standby event in their time window. Evidence for a link to standby is indirect: Symfony Process compares microtime(true) with the start time (wall clock, vendor Process.php line 1219), so a suspended process could exceed 300 s without a slow dump. This link is probable but not proven, and a real mysqldump hang was not ruled out. Not examined: why the test storage folder was empty after the failed run. No product code or test was changed for this item |

## F. Observed outside Section 13 (not changed)

- attempts.computer_id is NOT NULL while start() may not supply it.
- Inconsistent published and running exam statuses.
- orWhere bug in ComputerController::index.
- autoSubmit defect.
- Middleware on the computers routes.
- Heartbeat does not check expiry, and a heartbeat concurrent with a submit can set the
  status back to in_progress.
- The 24-hour default expiry of the withoutOverlapping mutex on the 3c-1 event.
- Root files cookies*.txt and output.txt: not touched and contents not reviewed.

## G. Verdict

Section 13 is complete with documented exceptions. It is not claimed to match an SRS,
because no SRS text exists for this section.