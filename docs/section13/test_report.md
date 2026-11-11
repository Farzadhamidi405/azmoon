# Section 13 - Test Report (Phase 5, real results)

All runs on Windows, PHP from C:\xampp1\php\php.exe, PHPUnit 12.5.35, database
azmoon_test. Success criterion: the "Tests:" line has no failed or error entries.
Exit code is recorded next to each run.

## 1. Per-file runs (php artisan test <file> --no-ansi)

| File | Exit | Result | Time |
|---|---|---|---|
| tests/Feature/Api/V1/BackupApiTest.php | 0 | 29 passed (60 assertions) | 8.9s |
| tests/Feature/Api/V1/SettingApiTest.php | 0 | 16 passed (41) | 7.5s |
| tests/Feature/Api/V1/NotificationApiTest.php | 0 | 28 passed (68) | 8.1s |
| tests/Feature/Services/BackupServiceTest.php | 0 | 5 passed (31) | 8.9s |
| tests/Feature/Services/ScheduledBackupServiceTest.php | 0 | 7 passed (60) | 8.5s |
| tests/Feature/Services/BackupNotificationTest.php | 0 | 10 passed (64) | 8.5s |
| tests/Feature/Console/RestoreBackupCommandTest.php | 0 | 20 passed (213) | 18.1s |
| tests/Feature/Console/RunScheduledBackupCommandTest.php | 0 | 6 passed (28) | 1.7s |
| tests/Feature/Console/BackupScheduleTest.php | 0 | 11 passed (94) | 1.8s |
| tests/Feature/Console/DetectDisconnectedAttemptsCommandTest.php | 0 | 13 passed (83) | 7.7s |
| tests/Feature/Services/NotificationServiceTest.php | 0 | 18 passed (42) | 7.4s |
| tests/Feature/SuspiciousSessionNotificationTest.php | 0 | 10 passed (61) | 7.9s |
| tests/Feature/ReconnectedNotificationTest.php | 0 | 10 passed (67) | 7.4s |
| tests/Feature/ExamCompletedNotificationTest.php | 0 | 10 passed (66) | 7.7s |
| tests/Feature/SettingControllerTest.php (0 bytes) | 1 | no Tests: line; "Class SettingControllerTest cannot be found" | 1.2s |

The 14 non-empty files add up to 193 tests and 978 assertions. NotificationApiTest uses
the #[Test] attribute (28 tests); counting "public function test_" gives 0 for it, so
only real run counts are used here.

## 2. Full-suite runs in Phase 5

| Run | Exit | Result | Time |
|---|---|---|---|
| php artisan test (1st) | 1 | 1 failed, 411 passed (1561 assertions) + empty-file WARN | 50.4s |
| vendor\bin\phpunit directly | 1 | OK, but there were issues: 412 tests, 1562 assertions, PHPUnit Warnings: 1 | 44.2s |
| php artisan test (2nd, in the follow-up run) | 1 | 412 passed (1561 assertions) + empty-file WARN | not recorded |

Baseline from Phase 4a: 412 passed (1562 assertions), exit 1.

Notes, stated as observed without a proven cause:
- The one failure in the 1st artisan run was tests/Feature/AntiCheatTest.php line 188
  (test risk_score_increases_with_more_events: expected score 15). It did not repeat in
  the Phase 5 runs and was investigated in Phase 6 (section 9, limitations.md L-21). A
  diagnostic run showed rows written in the same second sharing computed_at, so the
  test query latest('computed_at') had no tie-break. The test was changed in Phase 6.4
  (second key latest('id'), assertOk on the five POSTs); the product code was not.
  The statement written in Phase 5 that the test was not modified is therefore
  outdated. The internal MariaDB mechanism was not identified.
- The summary lines of those 5 single runs were empty because the script did not strip
  ANSI codes in that loop. Only exit=0 is evidence for them, not a test count.
- Assertion count differs by one: in Phase 5 artisan reported 1561 and phpunit 1562, and
  Phase 4a artisan reported 1562. The cause was not investigated. The final runs of
  Phase 6 reported 1566 for both runners (section 9.4); the difference was not
  explained.

## 3. The empty test file and exit code 1

tests/Feature/SettingControllerTest.php is 0 bytes (it was not changed). Every run that
scanned this file ended with exit code 1; every run that did not scan it ended with 0:
- Phase 4a table (8 runs): artisan on a file path 0, artisan on tests/Feature/Console 0,
  artisan on the empty file only 1, artisan --filter 1, phpunit file path 0, phpunit
  Console folder 0, phpunit --filter 1, phpunit full 1.
- Phase 5: 14 per-file runs exit 0; the empty file exit 1; full runs exit 1.
- Direct phpunit shows the same behavior and reports "PHPUnit Warnings: 1" with the
  message "Class SettingControllerTest cannot be found in ...\SettingControllerTest.php".
  So the warning comes from the PHPUnit test runner, not from artisan.
- Not proven by experiment: deleting or filling the file and re-running was not done
  (out of scope). Whether the exit code would become 0 is therefore the expected, not
  the demonstrated, outcome. The first Phase 5 artisan run also had a real failure, so
  its exit code alone cannot separate the two causes.
- Two other PHPUnit/PSR-4 warnings about autoloading exist outside Section 13
  (tests/Feature/AntiCheatEventFactory.php and
  app/Http/Controllers/ManualOverrideController.php).

## 4. Backup status values (BackupService, read in Phase 5)

BackupService writes pending (line 54), running (line 76), completed (line 149) and
failed (lines 61 and 159). The reason for the failed write at line 61, which comes
before running, was not read in this review.

## 5. Tool compatibility (Phase 5.4, names only)

mysqldump and mysql are MariaDB 10.4.32. All 9 mysqldump options in BackupService and the
mysql options in RestoreBackupCommand were found in the tool help. --connection is an
Artisan option, not a mysql option.

## 6. Not tested

- A real mysqldump, archive and restore against the real database azmoon: never executed. A real mysqldump and restore run only in RestoreBackupCommandTest::test_real_restore_on_dedicated_temporary_database, on a dedicated temporary database (section 9.3). According to Phase 5, the other tests use a faked process.
- schedule:run and backup:run-scheduled against a real database.
- The enabled schedule path through routes/console.php in an automated test (it was seen
  once with schedule:list using temporary environment variables).
- The invalid-schedule warning with the real database cache (tests use the array cache).
- Concurrency (two simultaneous backups) beyond mocked behavior.

## 7. Integrity of the code base

The final report compares file hashes against the baseline taken in Phase 5.0b (267
files, manifest hash 93A81D4F02D5DBC1CDBF0FDCC3465AD61B4B99AE20118E377EF15BC1994C9CB0).
Only files under docs/section13 were added.
Phase 6 note: after this baseline, tests/Feature/AntiCheatTest.php was changed in Phase 6.4 (tie-break and assertOk, see section 9). The sentence above describes Phase 5 only. Phase 6 comparisons use a separate manifest of 247 control files.
## 8. Final verification run (Phase 5.6, generated from real output)

- FULL artisan test | exit=1 | 47.7s | WARN  Class SettingControllerTest cannot be found in E:\azmoon\tests\Feature\SettingControllerTest.php | Tests:    412 passed (1561 assertions)
- FULL phpunit direct | exit=1 | 47.1s | 1) Class SettingControllerTest cannot be found in E:\azmoon\tests\Feature\SettingControllerTest.php | OK, but there were issues! | Tests: 412, Assertions: 1562, PHPUnit Warnings: 1.
- Hash comparison against the Phase 5.0b baseline: baseline=267 current=267 changed=0 added=0 removed=0
- Exit code 1 in both full runs is consistent with the empty test file warning (section 3); the Tests line is the success criterion.

## 9. Phase 6 results (AntiCheat intermittent failure, restore test timeout, final run)

Stated as observed. Facts come from command output; statements marked inference are not proven.

### 9.1 AntiCheatTest line 188 (see limitations.md L-21)

- Phase 6.1: 30 controlled runs, 29 passed and 1 failed (Failed asserting that 9 matches expected 15).
- Phase 6.3: 100 diagnostic runs with a temporary test (created and removed): 99 passed, 1 failed. In all 100 runs every POST returned 200 and 5 events and 5 risk score rows were stored (lost-row hypothesis rejected). In the failing run 3 rows shared the same computed_at second and the query latest('computed_at') returned id 3 (score 9) instead of id 5 (score 15) (same-second tie confirmed for that run). The query with latest('id') as a second key returned score 15 in 100 of 100 runs.
- Phase 6.4: tests/Feature/AntiCheatTest.php now uses latest('computed_at')->latest('id') and assertOk() on the five POSTs. The assertions on 15 and low were not changed. No product code was changed. The file ran 6 passed (23 assertions) with exit 0, and 10 separate runs of the single test passed.
- The temporary diagnostic test and its output file were deleted after their identity was checked.
- Not proven: the internal MariaDB mechanism behind the tie, and the real failure rate. The fix is not proof that the failure cannot recur.

### 9.2 Full-suite runs after the AntiCheat change

| Run | Exit | Result | Time |
|---|---|---|---|
| Phase 6.4 php artisan test | 1 | 412 passed (1566 assertions) + empty-file WARN | 40.18s |
| Phase 6.4 vendor\bin\phpunit direct | 1 | Tests: 412, Assertions: 1558, Failures: 1, PHPUnit Warnings: 1 (restore test, see 9.3) | 03:26:24.441 |
| Final check php artisan test (started 17:56:32) | 1 | 412 passed (1566 assertions) + empty-file WARN | 50.44s |
| Final check vendor\bin\phpunit direct (started 17:57:23) | 1 | OK, but there were issues: Tests: 412, Assertions: 1566, PHPUnit Warnings: 1 | 00:47.192 |

In the last two runs exit code 1 is accompanied by no failed test; the only warning is the empty file SettingControllerTest.php (section 3). The success criterion is the Tests line. All runs used database azmoon_test.

### 9.3 RestoreBackupCommandTest::test_real_restore_on_dedicated_temporary_database (see limitations.md L-24)

- The failure in the Phase 6.4 phpunit run was at line 461 (assertSame(0, $exit, $output)): the safety backup inside backup:restore, a real mysqldump on the temporary database azmoon_restore_test_..., exceeded the 300 second process timeout. The run took 03:26:24.441.
- The Windows System log shows a connected standby (event 506 at 14:13:38, event 507 at 17:39:17, about 3h25m39s) overlapping that run.
- Phase 6.5: three independent runs of the test with --filter all passed (exit 0, 1 passed, 28 assertions, about 15.2s, 15.4s and 15.4s). No temporary database and no mysqldump process remained.
- Final check: both full-suite runs above passed, including this test, and no standby event was found in their time window.
- Code evidence: the timeout is set with Process::timeout(config backup.process_timeout, default 300) in RestoreBackupCommand.php line 380 and BackupService.php line 190, and Symfony Process compares microtime(true) with the start time (vendor Process.php line 1219), that is wall clock time.
- Inference, not proven: a process suspended during standby can exceed 300 seconds of wall clock time without the dump itself being slow. A real hang of mysqldump was not ruled out. Why the test storage folder was empty after the failed run was not examined.

### 9.4 Assertion totals (see limitations.md L-22)

- Phase 5: artisan 1561 in the three runs that recorded it (Phase 4a baseline: 1562), phpunit direct 1562.
- Final check: artisan 1566 and phpunit direct 1566. The Phase 6.4 artisan run also reported 1566.
- AntiCheatTest gained 5 assertOk assertions. artisan 1561 + 5 = 1566 matches, but phpunit 1562 + 5 would be 1567 and 1566 was observed. The one-assertion difference of Phase 5 was therefore not reproduced and is not explained. It is not claimed as resolved.

### 9.5 Integrity

A control-file manifest of 247 files taken in Phase 6 was unchanged before and after the final check (changed or new = 0). artisan serve (PID 6008) was not touched.
