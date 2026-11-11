<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ReconnectedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function freeze(): Carbon
    {
        $now = Carbon::create(2026, 1, 15, 12, 0, 0, 'UTC');
        $this->travelTo($now);

        return $now;
    }

    /**
     * Creates an in-progress, connected attempt with a fresh heartbeat
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
            'last_heartbeat_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
    }

    /**
     * Creates an attempt in the state the disconnect command leaves behind.
     */
    private function makeDisconnectedAttempt(array $overrides = []): Attempt
    {
        return $this->makeAttempt(array_merge([
            'connection_status' => 'disconnected',
            'disconnected_at' => now()->subMinutes(2),
            'last_heartbeat_at' => now()->subMinutes(3),
            'reconnect_count' => 2,
        ], $overrides));
    }

    private function sendHeartbeat(Attempt $attempt, ?Candidate $as = null)
    {
        $candidate = $as ?? Candidate::findOrFail($attempt->candidate_id);

        return $this->actingAs($candidate, 'candidate')
            ->postJson("/api/v1/candidate/attempts/{$attempt->id}/heartbeat");
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

    private function reconnectNotifications()
    {
        return Notification::where('type', NotificationType::CandidateReconnected->value);
    }

    public function test_heartbeat_after_disconnect_restores_connection_and_counts_reconnect(): void
    {
        $now = $this->freeze();
        $attempt = $this->makeDisconnectedAttempt();

        $this->sendHeartbeat($attempt)
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'remaining_seconds', 'server_time']);

        $fresh = $attempt->fresh();
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame(3, (int) $fresh->reconnect_count);
        $this->assertTrue($fresh->last_heartbeat_at->equalTo($now));
        $this->assertNotNull($fresh->disconnected_at);
    }

    public function test_reconnect_notifies_only_authorized_active_recipients(): void
    {
        $this->freeze();
        $admin = $this->makeSuperAdmin();
        $viewer = $this->makeRoleUser(['notifications']);
        $this->makeRoleUser(['anticheat']);
        $this->makeSuperAdmin(false);
        $attempt = $this->makeDisconnectedAttempt();

        $this->sendHeartbeat($attempt)->assertStatus(200);

        $rows = $this->reconnectNotifications()->get();
        $expected = collect([$admin->id, $viewer->id])->sort()->values()->all();

        $this->assertCount(2, $rows);
        $this->assertEquals($expected, $rows->pluck('notifiable_user_id')->sort()->values()->all());
        foreach ($rows as $row) {
            $this->assertSame('attempt', $row->related_type);
            $this->assertSame($attempt->id, (int) $row->related_id);
            $this->assertNotEmpty($row->title);
            $this->assertStringContainsString((string) $attempt->id, (string) $row->body);
        }
    }

    public function test_regular_heartbeat_on_connected_attempt_does_not_count_or_notify(): void
    {
        $now = $this->freeze();
        $this->makeSuperAdmin();
        $attempt = $this->makeAttempt([
            'reconnect_count' => 4,
            'last_heartbeat_at' => $now->copy()->subSeconds(30),
        ]);

        $this->sendHeartbeat($attempt)->assertStatus(200);

        $fresh = $attempt->fresh();
        $this->assertSame(4, (int) $fresh->reconnect_count);
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertTrue($fresh->last_heartbeat_at->equalTo($now));
        $this->assertSame(0, $this->reconnectNotifications()->count());
    }

    public function test_legacy_disconnected_status_is_still_treated_as_reconnect(): void
    {
        $this->freeze();
        $this->makeSuperAdmin();
        $attempt = $this->makeAttempt([
            'status' => 'disconnected',
            'connection_status' => 'connected',
            'reconnect_count' => 1,
        ]);

        $this->sendHeartbeat($attempt)->assertStatus(200);

        $fresh = $attempt->fresh();
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertSame(2, (int) $fresh->reconnect_count);
        $this->assertSame(1, $this->reconnectNotifications()->count());
    }

    public function test_second_heartbeat_right_after_reconnect_changes_nothing_more(): void
    {
        $now = $this->freeze();
        $this->makeSuperAdmin();
        $attempt = $this->makeDisconnectedAttempt();

        $this->sendHeartbeat($attempt)->assertStatus(200);
        $this->travelTo($now->copy()->addSeconds(5));
        $this->sendHeartbeat($attempt)->assertStatus(200);

        $this->assertSame(3, (int) $attempt->fresh()->reconnect_count);
        $this->assertSame(1, $this->reconnectNotifications()->count());
    }

    public function test_recent_notification_is_not_duplicated_but_reconnect_is_still_counted(): void
    {
        $this->freeze();
        $admin = $this->makeSuperAdmin();
        $viewer = $this->makeRoleUser(['notifications']);
        $attempt = $this->makeDisconnectedAttempt(['reconnect_count' => 0]);

        Notification::create([
            'type' => NotificationType::CandidateReconnected->value,
            'title' => 'existing',
            'body' => 'existing',
            'related_type' => 'attempt',
            'related_id' => $attempt->id,
            'is_read' => false,
            'notifiable_user_id' => $admin->id,
        ]);

        $this->sendHeartbeat($attempt)->assertStatus(200);

        $this->assertSame(1, (int) $attempt->fresh()->reconnect_count);
        $this->assertSame(1, $this->reconnectNotifications()->where('notifiable_user_id', $admin->id)->count());
        $this->assertSame(1, $this->reconnectNotifications()->where('notifiable_user_id', $viewer->id)->count());
    }

    public function test_notification_error_does_not_break_heartbeat(): void
    {
        $this->freeze();
        Exceptions::fake();

        $this->mock(NotificationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('boom'));
        });

        $attempt = $this->makeDisconnectedAttempt();

        $this->sendHeartbeat($attempt)
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'remaining_seconds', 'server_time']);

        $fresh = $attempt->fresh();
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertSame(3, (int) $fresh->reconnect_count);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
    }

    public function test_notification_is_dispatched_after_commit_with_expected_arguments(): void
    {
        $this->freeze();
        $attempt = $this->makeDisconnectedAttempt();

        $baseLevel = DB::transactionLevel();
        $levels = [];

        $this->mock(NotificationService::class, function (MockInterface $mock) use ($attempt, &$levels) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with(
                    NotificationType::CandidateReconnected,
                    Mockery::type('string'),
                    Mockery::type('string'),
                    'attempt',
                    $attempt->id,
                    null,
                    600
                )
                ->andReturnUsing(function () use (&$levels) {
                    $levels[] = DB::transactionLevel();

                    return 1;
                });
        });

        $this->sendHeartbeat($attempt)->assertStatus(200);

        $this->assertSame([$baseLevel], $levels, 'dispatch must run after the heartbeat transaction has committed');
    }

    public function test_attempt_row_is_locked_before_it_is_updated(): void
    {
        $this->freeze();
        $this->makeSuperAdmin();
        $attempt = $this->makeDisconnectedAttempt();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->sendHeartbeat($attempt)->assertStatus(200);

        $lockIndex = null;
        $updateIndex = null;
        foreach ($queries as $i => $sql) {
            if ($lockIndex === null && str_contains($sql, 'from `attempts`') && str_contains($sql, 'for update')) {
                $lockIndex = $i;
            }
            if ($updateIndex === null && str_starts_with($sql, 'update `attempts`') && str_contains($sql, 'last_heartbeat_at')) {
                $updateIndex = $i;
            }
        }

        $this->assertNotNull($lockIndex, 'the attempt row must be selected with FOR UPDATE');
        $this->assertNotNull($updateIndex, 'the attempt row must be updated');
        $this->assertLessThan($updateIndex, $lockIndex);
    }

    public function test_full_cycle_disconnect_then_reconnect_and_rejected_heartbeats_notify_nothing(): void
    {
        $now = $this->freeze();
        $admin = $this->makeSuperAdmin();
        $attempt = $this->makeAttempt(['last_heartbeat_at' => $now->copy()->subSeconds(120)]);

        $this->artisan('attempts:detect-disconnected')->assertExitCode(0);

        $fresh = $attempt->fresh();
        $this->assertSame('disconnected', $fresh->connection_status);
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame(
            1,
            Notification::where('type', NotificationType::CandidateDisconnected->value)->count()
        );

        $this->travelTo($now->copy()->addSeconds(10));
        $this->sendHeartbeat($attempt)->assertStatus(200);

        $fresh = $attempt->fresh();
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertSame(1, (int) $fresh->reconnect_count);
        $this->assertSame(
            1,
            Notification::where('type', NotificationType::CandidateDisconnected->value)
                ->where('notifiable_user_id', $admin->id)->count()
        );
        $this->assertSame(1, $this->reconnectNotifications()->where('notifiable_user_id', $admin->id)->count());

        $otherCandidate = Candidate::factory()->create(['status' => 'present']);
        $this->sendHeartbeat($attempt, $otherCandidate)->assertStatus(403);

        $completed = $this->makeAttempt(['status' => 'completed']);
        $this->sendHeartbeat($completed)->assertStatus(422);

        $this->assertSame(1, $this->reconnectNotifications()->count());
        $this->assertSame(1, (int) $attempt->fresh()->reconnect_count);
        $this->assertSame(0, (int) $completed->fresh()->reconnect_count);
    }
}