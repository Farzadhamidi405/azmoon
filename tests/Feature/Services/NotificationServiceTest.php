<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $userCounter = 0;

    private function permission(string $permission): Permission
    {
        [$module, $action] = explode('.', $permission, 2);

        return Permission::firstOrCreate(['module' => $module, 'action' => $action]);
    }

    private function role(string $name, array $permissions = []): Role
    {
        $role = Role::firstOrCreate(['name' => $name], ['is_system_role' => false]);

        foreach ($permissions as $permission) {
            $role->permissions()->syncWithoutDetaching([$this->permission($permission)->id]);
        }

        return $role;
    }

    private function user(?Role $role, bool $active = true): User
    {
        $this->userCounter++;

        return User::factory()->create([
            'username' => 'svc_user_' . $this->userCounter,
            'role_id' => $role?->id,
            'is_active' => $active,
        ]);
    }

    /**
     * @return list<int>
     */
    private function recipientsOf(NotificationType $type): array
    {
        return Notification::query()
            ->where('type', $type->value)
            ->orderBy('notifiable_user_id')
            ->pluck('notifiable_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_dispatch_creates_one_row_per_eligible_user(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $u1 = $this->user($viewer);
        $u2 = $this->user($viewer);
        $u3 = $this->user($viewer);

        $count = (new NotificationService())->dispatch(
            NotificationType::ExamCompleted,
            'Exam completed',
            'Body text',
            'exam',
            42
        );

        $this->assertSame(3, $count);
        $this->assertSame([$u1->id, $u2->id, $u3->id], $this->recipientsOf(NotificationType::ExamCompleted));

        $row = Notification::query()->where('notifiable_user_id', $u1->id)->first();
        $this->assertSame('exam_completed', $row->type);
        $this->assertSame('Exam completed', $row->title);
        $this->assertSame('Body text', $row->body);
        $this->assertSame('exam', $row->related_type);
        $this->assertSame(42, (int) $row->related_id);
        $this->assertFalse($row->is_read);
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);

        $this->assertSame(0, Notification::query()->whereNull('notifiable_user_id')->count());
    }

    public function test_inactive_users_are_skipped_even_with_permission_or_super_admin(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $superAdmin = $this->role('Super Admin');

        $active = $this->user($viewer);
        $this->user($viewer, false);
        $this->user($superAdmin, false);

        (new NotificationService())->dispatch(NotificationType::ExamCompleted, 'Exam completed');

        $this->assertSame([$active->id], $this->recipientsOf(NotificationType::ExamCompleted));
    }

    public function test_users_without_permission_or_role_are_skipped(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $noPermission = $this->role('NoPermission', ['anticheat.view']);

        $eligible = $this->user($viewer);
        $this->user($noPermission);
        $this->user(null);

        (new NotificationService())->dispatch(NotificationType::ExamCompleted, 'Exam completed');

        $this->assertSame([$eligible->id], $this->recipientsOf(NotificationType::ExamCompleted));
    }

    public function test_super_admin_is_included_without_explicit_permission(): void
    {
        $superAdmin = $this->role('Super Admin');
        $sa = $this->user($superAdmin);

        (new NotificationService())->dispatch(NotificationType::ExamCompleted, 'Exam completed');

        $this->assertSame([$sa->id], $this->recipientsOf(NotificationType::ExamCompleted));
    }

    public function test_super_admin_only_types_exclude_regular_permission_holders(): void
    {
        $superAdmin = $this->role('Super Admin');
        $viewer = $this->role('Viewer', ['notifications.view']);

        $sa = $this->user($superAdmin);
        $this->user($viewer);

        $count = (new NotificationService())->dispatch(NotificationType::BackupFailed, 'Backup failed');

        $this->assertSame(1, $count);
        $this->assertSame([$sa->id], $this->recipientsOf(NotificationType::BackupFailed));
    }

    public function test_multi_permission_type_requires_all_permissions(): void
    {
        $viewOnly = $this->role('ViewOnly', ['notifications.view']);
        $antiCheatOnly = $this->role('AntiCheatOnly', ['anticheat.view']);
        $both = $this->role('Both', ['notifications.view', 'anticheat.view']);

        $this->user($viewOnly);
        $this->user($antiCheatOnly);
        $bothUser = $this->user($both);

        (new NotificationService())->dispatch(NotificationType::SuspiciousSession, 'Session needs review');

        $this->assertSame([$bothUser->id], $this->recipientsOf(NotificationType::SuspiciousSession));
    }

    public function test_exclude_user_id_is_respected(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $actor = $this->user($viewer);
        $other = $this->user($viewer);

        (new NotificationService())->dispatch(
            NotificationType::ExamCompleted,
            'Exam completed',
            excludeUserId: $actor->id
        );

        $this->assertSame([$other->id], $this->recipientsOf(NotificationType::ExamCompleted));
    }

    public function test_returns_zero_and_creates_nothing_when_there_are_no_recipients(): void
    {
        $count = (new NotificationService())->dispatch(NotificationType::ExamCompleted, 'Exam completed');

        $this->assertSame(0, $count);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_dedupe_skips_only_recipients_with_a_recent_matching_notification(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $alreadyNotified = $this->user($viewer);
        $fresh = $this->user($viewer);

        Notification::factory()->create([
            'type' => NotificationType::ExamCompleted->value,
            'related_type' => 'exam',
            'related_id' => 5,
            'notifiable_user_id' => $alreadyNotified->id,
        ]);

        $count = (new NotificationService())->dispatch(
            NotificationType::ExamCompleted,
            'Exam completed',
            relatedType: 'exam',
            relatedId: 5,
            dedupeSeconds: 600
        );

        $this->assertSame(1, $count);
        $this->assertSame(1, Notification::query()->where('notifiable_user_id', $alreadyNotified->id)->count());
        $this->assertSame(1, Notification::query()->where('notifiable_user_id', $fresh->id)->count());
    }

    public function test_dedupe_window_expired_allows_a_new_notification(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $user = $this->user($viewer);

        Notification::factory()->create([
            'type' => NotificationType::ExamCompleted->value,
            'related_type' => 'exam',
            'related_id' => 5,
            'notifiable_user_id' => $user->id,
            'created_at' => now()->subSeconds(601),
        ]);

        $count = (new NotificationService())->dispatch(
            NotificationType::ExamCompleted,
            'Exam completed',
            relatedType: 'exam',
            relatedId: 5,
            dedupeSeconds: 600
        );

        $this->assertSame(1, $count);
        $this->assertSame(2, Notification::query()->where('notifiable_user_id', $user->id)->count());
    }

    public function test_dedupe_does_not_cross_different_related_id_or_type(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $user = $this->user($viewer);

        Notification::factory()->create([
            'type' => NotificationType::ExamCompleted->value,
            'related_type' => 'exam',
            'related_id' => 5,
            'notifiable_user_id' => $user->id,
        ]);

        $service = new NotificationService();

        $differentId = $service->dispatch(
            NotificationType::ExamCompleted,
            'Exam completed',
            relatedType: 'exam',
            relatedId: 6,
            dedupeSeconds: 600
        );

        $differentType = $service->dispatch(
            NotificationType::CandidateDisconnected,
            'Candidate disconnected',
            relatedType: 'exam',
            relatedId: 5,
            dedupeSeconds: 600
        );

        $this->assertSame(1, $differentId);
        $this->assertSame(1, $differentType);
    }

    public function test_dedupe_requires_a_related_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NotificationService())->dispatch(
            NotificationType::ExamCompleted,
            'Exam completed',
            dedupeSeconds: 600
        );
    }

    public function test_related_type_and_id_must_be_given_together(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NotificationService())->dispatch(
            NotificationType::ExamCompleted,
            'Exam completed',
            relatedType: 'exam'
        );
    }

    public function test_empty_title_is_rejected_and_nothing_is_created(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $this->user($viewer);

        try {
            (new NotificationService())->dispatch(NotificationType::ExamCompleted, '   ');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, Notification::query()->count());
        }
    }

    public function test_non_enum_type_is_rejected_and_enum_values_are_stable(): void
    {
        $this->assertNull(NotificationType::tryFrom('not_a_type'));

        $this->assertSame(
            [
                'candidate_disconnected',
                'candidate_reconnected',
                'suspicious_session',
                'exam_completed',
                'backup_completed',
                'backup_failed',
            ],
            array_map(fn (NotificationType $type) => $type->value, NotificationType::cases())
        );

        $this->expectException(\TypeError::class);

        (new NotificationService())->dispatch('not_a_type', 'Title');
    }

    public function test_long_title_and_body_are_truncated(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);
        $this->user($viewer);

        (new NotificationService())->dispatch(
            NotificationType::ExamCompleted,
            str_repeat("\u{0639}", 300),
            str_repeat("\u{0639}", 2500)
        );

        $row = Notification::query()->first();

        $this->assertSame(NotificationService::MAX_TITLE_LENGTH, mb_strlen($row->title));
        $this->assertSame(NotificationService::MAX_BODY_LENGTH, mb_strlen($row->body));
    }

    public function test_recipients_match_has_permission_semantics(): void
    {
        $superAdmin = $this->role('Super Admin');
        $viewer = $this->role('Viewer', ['notifications.view']);
        $other = $this->role('Other', ['anticheat.view']);

        $this->user($superAdmin);
        $this->user($superAdmin, false);
        $this->user($viewer);
        $this->user($viewer, false);
        $this->user($other);
        $this->user($other);

        $expected = User::query()->get()
            ->filter(fn (User $user) => $user->is_active && $user->hasPermission('notifications.view'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        (new NotificationService())->dispatch(NotificationType::ExamCompleted, 'Exam completed');

        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $this->recipientsOf(NotificationType::ExamCompleted));
    }

    public function test_query_count_is_constant_for_fifty_recipients(): void
    {
        $viewer = $this->role('Viewer', ['notifications.view']);

        for ($i = 0; $i < 50; $i++) {
            $this->user($viewer);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $count = (new NotificationService())->dispatch(NotificationType::ExamCompleted, 'Exam completed');

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => strtolower(ltrim($sql)));

        DB::disableQueryLog();

        $this->assertSame(50, $count);
        $this->assertSame(1, $queries->filter(fn ($sql) => str_starts_with($sql, 'select'))->count());
        $this->assertSame(1, $queries->filter(fn ($sql) => str_starts_with($sql, 'insert'))->count());
        $this->assertSame(0, $queries->filter(fn ($sql) => str_starts_with($sql, 'update') || str_starts_with($sql, 'delete'))->count());
        $this->assertSame(50, Notification::query()->count());
    }
}