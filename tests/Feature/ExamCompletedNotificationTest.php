<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Exam;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ExamCompletedNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeSuperAdmin(bool $active = true): User
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(
            ['name' => 'Super Admin'],
            ['is_system_role' => true]
        ));

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => $active,
        ]);
    }

    private function makeNamedRoleUser(string $roleName, array $modules, bool $active = true): User
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(
            ['name' => $roleName],
            ['is_system_role' => true]
        ));

        foreach ($modules as $module) {
            $permission = Permission::unguarded(fn () => Permission::firstOrCreate([
                'module' => $module,
                'action' => 'view',
            ]));

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => $active,
        ]);
    }

    private function makeRoleUser(array $modules, bool $active = true): User
    {
        return $this->makeNamedRoleUser('Test Role ' . uniqid(), $modules, $active);
    }

    private function makeExam(string $status, int $creatorId): Exam
    {
        return Exam::factory()->create([
            'status' => $status,
            'created_by' => $creatorId,
        ]);
    }

    private function changeStatus(User $user, Exam $exam, string $status): TestResponse
    {
        Sanctum::actingAs($user, [], 'admin');

        return $this->patchJson("/api/v1/admin/exams/{$exam->id}/status", [
            'status' => $status,
        ]);
    }

    private function countFor(User $user, Exam $exam): int
    {
        return Notification::query()
            ->where('notifiable_user_id', $user->id)
            ->where('type', NotificationType::ExamCompleted->value)
            ->where('related_type', 'exam')
            ->where('related_id', $exam->id)
            ->count();
    }

    private function completedCount(): int
    {
        return Notification::query()
            ->where('type', NotificationType::ExamCompleted->value)
            ->count();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_running_to_completed_notifies_each_eligible_active_user_once(): void
    {
        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $superAdmin = $this->makeSuperAdmin();
        $otherExamAdmin = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $otherRoleUser = $this->makeRoleUser(['notifications']);

        $inactiveUser = $this->makeRoleUser(['notifications'], false);
        $anticheatOnly = $this->makeRoleUser(['anticheat']);
        $noPermission = $this->makeRoleUser([]);

        $exam = $this->makeExam('running', $actor->id);

        $this->changeStatus($actor, $exam, 'completed')->assertOk();

        $this->assertSame('completed', $exam->fresh()->status);

        $this->assertSame(1, $this->countFor($superAdmin, $exam));
        $this->assertSame(1, $this->countFor($otherExamAdmin, $exam));
        $this->assertSame(1, $this->countFor($otherRoleUser, $exam));

        $this->assertSame(0, $this->countFor($actor, $exam));
        $this->assertSame(0, $this->countFor($inactiveUser, $exam));
        $this->assertSame(0, $this->countFor($anticheatOnly, $exam));
        $this->assertSame(0, $this->countFor($noPermission, $exam));

        $this->assertSame(3, $this->completedCount());

        $row = Notification::query()
            ->where('notifiable_user_id', $superAdmin->id)
            ->where('type', NotificationType::ExamCompleted->value)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotSame('', trim((string) $row->title));
        $this->assertStringContainsString((string) $exam->id, (string) $row->body);
        $this->assertFalse((bool) $row->is_read);
    }

    public function test_actor_who_is_super_admin_is_excluded_but_other_super_admin_is_notified(): void
    {
        $actor = $this->makeSuperAdmin();
        $otherSuperAdmin = $this->makeSuperAdmin();

        $exam = $this->makeExam('running', $actor->id);

        $this->changeStatus($actor, $exam, 'completed')->assertOk();

        $this->assertSame(0, $this->countFor($actor, $exam));
        $this->assertSame(1, $this->countFor($otherSuperAdmin, $exam));
        $this->assertSame(1, $this->completedCount());
    }

    public function test_other_transitions_do_not_create_exam_completed_notifications(): void
    {
        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $this->makeSuperAdmin();
        $this->makeRoleUser(['notifications']);

        $draft = $this->makeExam('draft', $actor->id);
        $published = $this->makeExam('published', $actor->id);
        $completedA = $this->makeExam('completed', $actor->id);
        $completedB = $this->makeExam('completed', $actor->id);

        $this->changeStatus($actor, $draft, 'under_review')->assertOk();
        $this->changeStatus($actor, $published, 'running')->assertOk();
        $this->changeStatus($actor, $completedA, 'reviewed')->assertOk();
        $this->changeStatus($actor, $completedB, 'archived')->assertOk();

        $this->assertSame('under_review', $draft->fresh()->status);
        $this->assertSame('running', $published->fresh()->status);
        $this->assertSame('reviewed', $completedA->fresh()->status);
        $this->assertSame('archived', $completedB->fresh()->status);

        $this->assertSame(0, $this->completedCount());
    }

    public function test_invalid_transition_to_completed_returns_422_and_creates_no_notification(): void
    {
        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $this->makeSuperAdmin();

        $exam = $this->makeExam('published', $actor->id);

        $response = $this->changeStatus($actor, $exam, 'completed');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        $this->assertSame('published', $exam->fresh()->status);
        $this->assertSame(0, $this->completedCount());
    }

    public function test_unauthorized_role_gets_403_and_creates_no_notification(): void
    {
        $creator = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $this->makeSuperAdmin();

        $reviewer = $this->makeNamedRoleUser('Reviewer', ['notifications']);

        $exam = $this->makeExam('running', $creator->id);

        $this->changeStatus($reviewer, $exam, 'completed')->assertStatus(403);

        $this->assertSame('running', $exam->fresh()->status);
        $this->assertSame(0, $this->completedCount());
    }

    public function test_notification_service_failure_does_not_break_status_change_and_is_reported(): void
    {
        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $exam = $this->makeExam('running', $actor->id);

        Exceptions::fake();

        $this->mock(NotificationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('exam notification boom'));
        });

        $this->changeStatus($actor, $exam, 'completed')->assertOk();

        $this->assertSame('completed', $exam->fresh()->status);

        Exceptions::assertReported(
            fn (RuntimeException $e) => $e->getMessage() === 'exam notification boom'
        );
    }

    public function test_dispatch_is_called_once_with_expected_arguments_after_status_is_persisted(): void
    {
        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $exam = $this->makeExam('running', $actor->id);
        $examId = $exam->id;

        $captured = null;
        $statusAtCall = null;

        $this->mock(NotificationService::class, function (MockInterface $mock) use (&$captured, &$statusAtCall, $examId) {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andReturnUsing(function (...$args) use (&$captured, &$statusAtCall, $examId) {
                    $captured = $args;
                    $statusAtCall = Exam::query()->whereKey($examId)->value('status');

                    return 0;
                });
        });

        $this->changeStatus($actor, $exam, 'completed')->assertOk();

        $this->assertNotNull($captured);
        $this->assertSame('completed', $statusAtCall);

        $this->assertSame(NotificationType::ExamCompleted, $captured[0]);
        $this->assertIsString($captured[1]);
        $this->assertNotSame('', trim($captured[1]));
        $this->assertStringContainsString((string) $examId, (string) $captured[2]);
        $this->assertSame('exam', $captured[3]);
        $this->assertSame($examId, $captured[4]);
        $this->assertSame($actor->id, $captured[5]);
        $this->assertSame(600, $captured[6]);
    }

    public function test_repeated_completion_within_dedupe_window_does_not_duplicate_notifications(): void
    {
        $this->travelTo(Carbon::create(2026, 1, 15, 12, 0, 0, 'UTC'));

        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $superAdmin = $this->makeSuperAdmin();
        $exam = $this->makeExam('running', $actor->id);

        $this->changeStatus($actor, $exam, 'completed')->assertOk();
        $this->assertSame(1, $this->countFor($superAdmin, $exam));

        Exam::query()->whereKey($exam->id)->update(['status' => 'running']);
        $this->travelTo(now()->addSeconds(60));

        $this->changeStatus($actor, $exam, 'completed')->assertOk();

        $this->assertSame('completed', $exam->fresh()->status);
        $this->assertSame(1, $this->countFor($superAdmin, $exam));
        $this->assertSame(1, $this->completedCount());
    }

    public function test_actor_as_only_eligible_recipient_results_in_no_notification_rows(): void
    {
        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $exam = $this->makeExam('running', $actor->id);

        $this->changeStatus($actor, $exam, 'completed')->assertOk();

        $this->assertSame('completed', $exam->fresh()->status);
        $this->assertSame(0, $this->completedCount());
    }

    public function test_successful_response_has_expected_json_structure(): void
    {
        $actor = $this->makeNamedRoleUser('Exam Administrator', ['notifications']);
        $exam = $this->makeExam('running', $actor->id);

        $response = $this->changeStatus($actor, $exam, 'completed');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['status'],
        ]);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'completed');
    }
}