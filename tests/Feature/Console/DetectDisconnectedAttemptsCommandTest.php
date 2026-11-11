<?php

namespace Tests\Feature\Console;

use App\Enums\NotificationType;
use App\Models\Attempt;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\ExamSnapshot;
use App\Models\Lab;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DetectDisconnectedAttemptsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function freeze(): Carbon
    {
        $now = Carbon::create(2026, 1, 15, 12, 0, 0, 'UTC');
        $this->travelTo($now);

        return $now;
    }

    /**
     * Creates an in-progress, connected attempt with a stale heartbeat (120s)
     * and a future expiry, unless overridden.
     */
    private function makeAttempt(array $overrides = []): Attempt
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

        return Attempt::factory()->create(array_merge([
            'candidate_id' => $candidate->id,
            'exam_id' => $exam->id,
            'candidate_assignment_id' => $assignment->id,
            'exam_snapshot_id' => $snapshot->id,
            'computer_id' => $computer->id,
            'status' => 'in_progress',
            'connection_status' => 'connected',
            'last_heartbeat_at' => now()->subSeconds(120),
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
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
     * @param array<int, string> $modules modules that get the "view" action
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

    private function disconnectNotifications()
    {
        return Notification::where('type', NotificationType::CandidateDisconnected->value);
    }

    public function test_marks_attempt_with_stale_heartbeat_as_disconnected_without_touching_other_fields(): void
    {
        $now = $this->freeze();
        $heartbeat = $now->copy()->subSeconds(120);
        $attempt = $this->makeAttempt(['reconnect_count' => 3, 'last_heartbeat_at' => $heartbeat]);

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $fresh = $attempt->fresh();
        $this->assertSame('disconnected', $fresh->connection_status);
        $this->assertNotNull($fresh->disconnected_at);
        $this->assertTrue($fresh->disconnected_at->equalTo($now));
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame(3, (int) $fresh->reconnect_count);
        $this->assertTrue($fresh->last_heartbeat_at->equalTo($heartbeat));
    }

    public function test_threshold_is_strict_ninety_seconds_is_connected_ninety_one_is_disconnected(): void
    {
        $now = $this->freeze();
        $onTime = $this->makeAttempt(['last_heartbeat_at' => $now->copy()->subSeconds(90)]);
        $late = $this->makeAttempt(['last_heartbeat_at' => $now->copy()->subSeconds(91)]);

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $this->assertSame('connected', $onTime->fresh()->connection_status);
        $this->assertNull($onTime->fresh()->disconnected_at);
        $this->assertSame('disconnected', $late->fresh()->connection_status);
        $this->assertNotNull($late->fresh()->disconnected_at);
    }

    public function test_ignores_attempts_that_are_not_in_progress(): void
    {
        $this->freeze();
        $this->makeSuperAdmin();

        $attempts = [];
        foreach (['completed', 'auto_submitted', 'void', 'disconnected'] as $status) {
            $attempts[$status] = $this->makeAttempt(['status' => $status]);
        }

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        foreach ($attempts as $status => $attempt) {
            $fresh = $attempt->fresh();
            $this->assertSame('connected', $fresh->connection_status, "status {$status} must be ignored");
            $this->assertNull($fresh->disconnected_at, "status {$status} must be ignored");
            $this->assertSame($status, $fresh->status);
        }
        $this->assertSame(0, $this->disconnectNotifications()->count());
    }

    public function test_already_disconnected_attempt_keeps_disconnected_at_and_creates_no_notification(): void
    {
        $now = $this->freeze();
        $this->makeSuperAdmin();
        $originalDisconnectedAt = $now->copy()->subSeconds(300);
        $attempt = $this->makeAttempt([
            'connection_status' => 'disconnected',
            'disconnected_at' => $originalDisconnectedAt,
        ]);

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $fresh = $attempt->fresh();
        $this->assertSame('disconnected', $fresh->connection_status);
        $this->assertTrue($fresh->disconnected_at->equalTo($originalDisconnectedAt));
        $this->assertSame(0, $this->disconnectNotifications()->count());
    }

    public function test_ignores_attempts_without_heartbeat_and_expired_attempts(): void
    {
        $now = $this->freeze();
        $this->makeSuperAdmin();
        $noHeartbeat = $this->makeAttempt(['last_heartbeat_at' => null]);
        $expired = $this->makeAttempt(['expires_at' => $now->copy()->subMinute()]);

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        foreach ([$noHeartbeat, $expired] as $attempt) {
            $fresh = $attempt->fresh();
            $this->assertSame('connected', $fresh->connection_status);
            $this->assertNull($fresh->disconnected_at);
        }
        $this->assertSame(0, $this->disconnectNotifications()->count());
    }

    public function test_creates_one_notification_per_attempt_for_each_authorized_active_recipient(): void
    {
        $this->freeze();
        $admin = $this->makeSuperAdmin();
        $viewer = $this->makeRoleUser(['notifications']);
        $this->makeRoleUser(['anticheat']);
        $this->makeSuperAdmin(false);

        $first = $this->makeAttempt();
        $second = $this->makeAttempt();

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $expected = collect([$admin->id, $viewer->id])->sort()->values()->all();

        foreach ([$first, $second] as $attempt) {
            $rows = $this->disconnectNotifications()->where('related_id', $attempt->id)->get();

            $this->assertCount(2, $rows);
            $this->assertEquals($expected, $rows->pluck('notifiable_user_id')->sort()->values()->all());
            foreach ($rows as $row) {
                $this->assertSame('attempt', $row->related_type);
                $this->assertNotEmpty($row->title);
                $this->assertStringContainsString((string) $attempt->id, (string) $row->body);
            }
        }
        $this->assertSame(4, $this->disconnectNotifications()->count());
    }

    public function test_second_run_changes_nothing(): void
    {
        $now = $this->freeze();
        $this->makeSuperAdmin();
        $attempt = $this->makeAttempt();

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);
        $notificationsAfterFirst = $this->disconnectNotifications()->count();
        $this->assertSame(1, $notificationsAfterFirst);

        $this->travelTo($now->copy()->addSeconds(30));
        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $this->assertTrue($attempt->fresh()->disconnected_at->equalTo($now));
        $this->assertSame($notificationsAfterFirst, $this->disconnectNotifications()->count());
    }

    public function test_recent_notification_for_same_attempt_is_not_duplicated_per_recipient(): void
    {
        $this->freeze();
        $admin = $this->makeSuperAdmin();
        $viewer = $this->makeRoleUser(['notifications']);
        $attempt = $this->makeAttempt();

        Notification::create([
            'type' => NotificationType::CandidateDisconnected->value,
            'title' => 'existing',
            'body' => 'existing',
            'related_type' => 'attempt',
            'related_id' => $attempt->id,
            'is_read' => false,
            'notifiable_user_id' => $admin->id,
        ]);

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $this->assertSame('disconnected', $attempt->fresh()->connection_status);
        $this->assertSame(1, $this->disconnectNotifications()->where('notifiable_user_id', $admin->id)->count());
        $this->assertSame(1, $this->disconnectNotifications()->where('notifiable_user_id', $viewer->id)->count());
    }

    public function test_heartbeat_arriving_after_selection_prevents_disconnect_and_notification(): void
    {
        $this->freeze();
        $this->makeSuperAdmin();
        $attempt = $this->makeAttempt();

        $fired = false;
        Attempt::retrieved(function (Attempt $retrieved) use (&$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            DB::table('attempts')->where('id', $retrieved->id)->update(['last_heartbeat_at' => now()]);
        });

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $this->assertTrue($fired, 'the simulated heartbeat hook must have run');
        $fresh = $attempt->fresh();
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertNull($fresh->disconnected_at);
        $this->assertSame(0, $this->disconnectNotifications()->count());
    }

    public function test_notification_failure_is_reported_but_attempt_stays_disconnected_and_command_succeeds(): void
    {
        $this->freeze();
        Exceptions::fake();

        $this->mock(NotificationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('boom'));
        });

        $attempt = $this->makeAttempt();

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $this->assertSame('disconnected', $attempt->fresh()->connection_status);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
    }

    public function test_respects_configured_heartbeat_timeout(): void
    {
        $now = $this->freeze();
        config(['attempt.heartbeat_timeout_seconds' => 300]);

        $withinTimeout = $this->makeAttempt(['last_heartbeat_at' => $now->copy()->subSeconds(200)]);
        $beyondTimeout = $this->makeAttempt(['last_heartbeat_at' => $now->copy()->subSeconds(301)]);

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $this->assertSame('connected', $withinTimeout->fresh()->connection_status);
        $this->assertSame('disconnected', $beyondTimeout->fresh()->connection_status);
    }

    public function test_invalid_configuration_fails_without_changing_anything(): void
    {
        $this->freeze();
        $this->makeSuperAdmin();
        $attempt = $this->makeAttempt();

        config(['attempt.heartbeat_timeout_seconds' => 0]);
        $this->artisan('attempts:detect-disconnected')->assertExitCode(1);

        config([
            'attempt.heartbeat_timeout_seconds' => 90,
            'attempt.disconnect_notification_dedupe_seconds' => 0,
        ]);
        $this->artisan('attempts:detect-disconnected')->assertExitCode(1);

        $fresh = $attempt->fresh();
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertNull($fresh->disconnected_at);
        $this->assertSame(0, $this->disconnectNotifications()->count());
    }

    public function test_command_is_scheduled_every_minute_without_overlapping(): void
    {
        Artisan::call('schedule:list');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'attempts:detect-disconnected'))
            ->values();

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}