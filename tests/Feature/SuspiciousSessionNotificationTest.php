<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\AntiCheatEvent;
use App\Models\Attempt;
use App\Models\AttemptRiskScore;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\ExamSnapshotQuestion;
use App\Models\Lab;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

class SuspiciousSessionNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeActiveAttempt(): array
    {
        $lab = Lab::factory()->create();
        $computer = Computer::factory()->create(['lab_id' => $lab->id, 'status' => 'online']);
        $exam = Exam::factory()->create(['status' => 'published', 'duration_minutes' => 60]);
        $shift = Shift::factory()->create(['exam_id' => $exam->id, 'lab_id' => $lab->id]);
        $candidate = Candidate::factory()->create(['status' => 'present']);
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id' => $exam->id,
            'shift_id' => $shift->id,
            'lab_id' => $lab->id,
            'computer_id' => $computer->id,
        ]);
        $snapshot = ExamSnapshot::factory()->create(['exam_id' => $exam->id]);
        $section = ExamSection::factory()->create(['exam_id' => $exam->id]);
        ExamSnapshotQuestion::factory()->count(3)->create([
            'exam_snapshot_id' => $snapshot->id,
            'exam_section_id' => $section->id,
        ]);

        $attempt = Attempt::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id' => $exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id' => $snapshot->id,
            'computer_id' => $computer->id,
            'status' => 'in_progress',
            'expires_at' => now()->addMinutes(60),
        ]);

        return compact('candidate', 'attempt');
    }

    private function makeSuperAdmin(bool $active = true): User
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(
            ['name' => 'Super Admin'],
            ['is_system_role' => true]
        ));

        return User::factory()->create(['role_id' => $role->id, 'is_active' => $active]);
    }

    /**
     * Creates a user with a fresh non-system role holding exactly the given permissions.
     *
     * @param  array<int, string>  $modules  modules that get the "view" action
     */
    private function makeRoleUser(array $modules): User
    {
        $role = Role::unguarded(fn () => Role::create([
            'name' => 'Test Role '.uniqid(),
            'is_system_role' => false,
        ]));

        foreach ($modules as $module) {
            $permission = Permission::unguarded(fn () => Permission::firstOrCreate([
                'module' => $module,
                'action' => 'view',
            ]));
            $role->permissions()->attach($permission->id);
        }

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function seedEvents(Attempt $attempt, int $count, string $type = 'multiple_login'): void
    {
        AntiCheatEvent::factory()->count($count)->create([
            'attempt_id' => $attempt->id,
            'event_type' => $type,
        ]);
    }

    private function seedScore(Attempt $attempt, int $score, string $level, $computedAt = null): AttemptRiskScore
    {
        return AttemptRiskScore::create([
            'attempt_id' => $attempt->id,
            'score' => $score,
            'level' => $level,
            'computed_at' => $computedAt ?? now(),
        ]);
    }

    private function postEvent(Candidate $candidate, Attempt $attempt, string $type = 'tab_switch')
    {
        return $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attempt->id}/anti-cheat/event", [
                'event_type' => $type,
            ]);
    }

    private function suspiciousNotifications()
    {
        return Notification::query()
            ->where('type', NotificationType::SuspiciousSession->value)
            ->get();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_crossing_into_high_notifies_only_authorized_recipients(): void
    {
        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $superAdmin = $this->makeSuperAdmin();
        $this->makeSuperAdmin(false);
        $both = $this->makeRoleUser(['notifications', 'anticheat']);
        $this->makeRoleUser(['notifications']);
        $this->makeRoleUser(['anticheat']);

        $this->seedEvents($attempt, 6);          // 60 points
        $this->seedScore($attempt, 60, 'medium');

        $response = $this->postEvent($data['candidate'], $attempt);   // +3 => 63 => high

        $response->assertStatus(200)->assertJsonPath('success', true);

        $rows = $this->suspiciousNotifications();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$superAdmin->id, $both->id],
            array_map('intval', $rows->pluck('notifiable_user_id')->all())
        );
    }

    public function test_notification_text_is_neutral_and_references_the_attempt(): void
    {
        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];
        $candidate = $data['candidate'];

        $this->makeSuperAdmin();
        $this->seedEvents($attempt, 6);
        $this->seedScore($attempt, 60, 'medium');

        $this->postEvent($candidate, $attempt)->assertStatus(200);

        $rows = $this->suspiciousNotifications();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('attempt', $row->related_type);
        $this->assertSame($attempt->id, (int) $row->related_id);
        $this->assertFalse((bool) $row->is_read);
        $this->assertNotSame('', trim($row->title));
        $this->assertStringContainsString((string) $attempt->id, (string) $row->body);

        $text = $row->title.' '.$row->body;
        $forbidden = ['تقلب', 'cheat', 'tab_switch', 'multiple_login'];

        foreach ($candidate->getAttributes() as $value) {
            if (is_string($value) && strlen($value) >= 4) {
                $forbidden[] = $value;
            }
        }

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
    }

    public function test_low_and_medium_levels_do_not_notify(): void
    {
        $this->makeSuperAdmin();

        $low = $this->makeActiveAttempt();
        $this->postEvent($low['candidate'], $low['attempt'])->assertStatus(200);   // 3 => low

        $medium = $this->makeActiveAttempt();
        $this->seedEvents($medium['attempt'], 4);                                  // 40
        $this->postEvent($medium['candidate'], $medium['attempt'])->assertStatus(200);   // 43 => medium

        $this->assertSame(
            'low',
            AttemptRiskScore::where('attempt_id', $low['attempt']->id)->orderByDesc('id')->value('level')
        );
        $this->assertSame(
            'medium',
            AttemptRiskScore::where('attempt_id', $medium['attempt']->id)->orderByDesc('id')->value('level')
        );
        $this->assertCount(0, $this->suspiciousNotifications());
    }

    public function test_already_high_attempt_does_not_notify_again(): void
    {
        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $this->makeSuperAdmin();
        $this->seedEvents($attempt, 7);          // 70 points
        $this->seedScore($attempt, 70, 'high');

        $this->postEvent($data['candidate'], $attempt)->assertStatus(200);   // 73, still high

        $this->assertSame(
            'high',
            AttemptRiskScore::where('attempt_id', $attempt->id)->orderByDesc('id')->value('level')
        );
        $this->assertCount(0, $this->suspiciousNotifications());
    }

    public function test_missing_previous_score_notifies_once_and_not_on_next_event(): void
    {
        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $this->makeSuperAdmin();
        $this->seedEvents($attempt, 6);          // 60 points, no stored score row

        $this->postEvent($data['candidate'], $attempt)->assertStatus(200);   // 63 => high
        $this->assertCount(1, $this->suspiciousNotifications());

        $this->postEvent($data['candidate'], $attempt)->assertStatus(200);   // 66, previous is high now
        $this->assertCount(1, $this->suspiciousNotifications());
    }

    public function test_previous_level_is_taken_from_the_highest_id_not_from_computed_at(): void
    {
        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $this->makeSuperAdmin();
        $this->seedEvents($attempt, 6);

        // Same computed_at on purpose: only the id decides which row is the latest.
        $timestamp = now()->startOfSecond();
        $older = $this->seedScore($attempt, 70, 'high', $timestamp);
        $newer = $this->seedScore($attempt, 60, 'medium', $timestamp);
        $this->assertGreaterThan($older->id, $newer->id);

        $this->postEvent($data['candidate'], $attempt)->assertStatus(200);   // medium -> high

        $this->assertCount(1, $this->suspiciousNotifications());
    }

    public function test_recent_duplicate_notification_is_not_created_again(): void
    {
        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $adminA = $this->makeSuperAdmin();
        $adminB = $this->makeSuperAdmin();

        Notification::create([
            'type' => NotificationType::SuspiciousSession->value,
            'title' => 'existing',
            'body' => 'existing',
            'related_type' => 'attempt',
            'related_id' => $attempt->id,
            'is_read' => false,
            'notifiable_user_id' => $adminA->id,
        ]);

        $this->seedEvents($attempt, 6);
        $this->seedScore($attempt, 60, 'medium');

        $this->postEvent($data['candidate'], $attempt)->assertStatus(200);

        $rows = $this->suspiciousNotifications();
        $this->assertCount(1, $rows->where('notifiable_user_id', $adminA->id));
        $this->assertCount(1, $rows->where('notifiable_user_id', $adminB->id));
        $this->assertCount(2, $rows);
    }

    public function test_notification_is_dispatched_after_commit_with_expected_arguments(): void
    {
        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $this->seedEvents($attempt, 6);
        $this->seedScore($attempt, 60, 'medium');

        $baseLevel = DB::transactionLevel();
        $captured = [];

        $this->mock(NotificationService::class, function ($mock) use (&$captured, $attempt) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andReturnUsing(function (...$args) use (&$captured, $attempt) {
                    $captured = [
                        'args' => $args,
                        'level' => DB::transactionLevel(),
                        'stored' => AttemptRiskScore::where('attempt_id', $attempt->id)
                            ->where('level', 'high')
                            ->exists(),
                    ];

                    return 1;
                });
        });

        $this->postEvent($data['candidate'], $attempt)->assertStatus(200);

        $this->assertNotEmpty($captured, 'dispatch was not called.');
        $this->assertSame($baseLevel, $captured['level'], 'dispatch must run outside the event transaction.');
        $this->assertTrue($captured['stored'], 'The high score row must already be stored at dispatch time.');
        $this->assertSame(NotificationType::SuspiciousSession, $captured['args'][0]);
        $this->assertSame('attempt', $captured['args'][3]);
        $this->assertSame($attempt->id, $captured['args'][4]);
        $this->assertSame(600, $captured['args'][6]);
    }

    public function test_attempt_row_is_locked_before_the_event_is_inserted(): void
    {
        $data = $this->makeActiveAttempt();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->postEvent($data['candidate'], $data['attempt'])->assertStatus(200);

        $lockIndex = null;
        $insertIndex = null;

        foreach ($queries as $i => $sql) {
            if ($lockIndex === null && str_contains($sql, 'for update') && str_contains($sql, 'from `attempts`')) {
                $lockIndex = $i;
            }
            if ($insertIndex === null && str_contains($sql, 'insert into `anti_cheat_events`')) {
                $insertIndex = $i;
            }
        }

        $this->assertNotNull($lockIndex, 'No FOR UPDATE query on attempts was executed.');
        $this->assertNotNull($insertIndex, 'No insert into anti_cheat_events was executed.');
        $this->assertLessThan($insertIndex, $lockIndex);
    }

    public function test_notification_error_does_not_break_event_recording(): void
    {
        Exceptions::fake();

        $data = $this->makeActiveAttempt();
        $attempt = $data['attempt'];

        $this->seedEvents($attempt, 6);
        $this->seedScore($attempt, 60, 'medium');

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('notify boom'));
        });

        $response = $this->postEvent($data['candidate'], $attempt);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertSame(
            7,
            AntiCheatEvent::where('attempt_id', $attempt->id)->count()
        );
        $this->assertSame(
            'high',
            AttemptRiskScore::where('attempt_id', $attempt->id)->orderByDesc('id')->value('level')
        );

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'notify boom');
    }
}