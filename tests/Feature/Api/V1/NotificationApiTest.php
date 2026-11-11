<?php

namespace Tests\Feature\Api\V1;

use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تست‌های Step 12.9 — Notifications API.
 * فقط خواندن + علامت‌گذاری وضعیت خوانده‌شدن؛ بدون store/update/destroy.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/notifications';

    /**
     * ساخت کاربر ادمین با/بدون permission مربوط به notifications.view.
     */
    private function makeUser(bool $withPermission): User
    {
        $role = Role::create([
            'name' => 'Test Role ' . uniqid(),
            'is_system_role' => false,
        ]);

        if ($withPermission) {
            $permission = Permission::firstOrCreate(['module' => 'notifications', 'action' => 'view']);
            $role->permissions()->attach($permission->id);
        }

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    // ===================== AUTH / PERMISSION =====================

    #[Test]
    public function guest_cannot_list_notifications(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    #[Test]
    public function guest_cannot_view_unread_count(): void
    {
        $this->getJson(self::URL . '/unread-count')->assertStatus(401);
    }

    #[Test]
    public function guest_cannot_mark_all_read(): void
    {
        $this->patchJson(self::URL . '/read-all')->assertStatus(401);
    }

    #[Test]
    public function guest_cannot_view_notification(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        $this->getJson(self::URL . '/' . $notification->id)->assertStatus(401);
    }

    #[Test]
    public function guest_cannot_mark_notification_as_read(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        $this->patchJson(self::URL . '/' . $notification->id . '/read')->assertStatus(401);
    }

    #[Test]
    public function user_without_permission_gets_403_on_index(): void
    {
        $user = $this->makeUser(false);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL)->assertStatus(403);
    }

    #[Test]
    public function user_without_permission_gets_403_on_unread_count(): void
    {
        $user = $this->makeUser(false);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '/unread-count')->assertStatus(403);
    }

    #[Test]
    public function user_without_permission_gets_403_on_show(): void
    {
        $user = $this->makeUser(false);
        $notification = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '/' . $notification->id)->assertStatus(403);
    }

    #[Test]
    public function user_without_permission_gets_403_on_mark_read(): void
    {
        $user = $this->makeUser(false);
        $notification = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        Sanctum::actingAs($user, [], 'admin');

        $this->patchJson(self::URL . '/' . $notification->id . '/read')->assertStatus(403);
    }

    #[Test]
    public function user_without_permission_gets_403_on_read_all(): void
    {
        $user = $this->makeUser(false);

        Sanctum::actingAs($user, [], 'admin');

        $this->patchJson(self::URL . '/read-all')->assertStatus(403);
    }

    // ===================== INDEX =====================

    #[Test]
    public function user_sees_only_own_and_broadcast_notifications(): void
    {
        $user = $this->makeUser(true);
        $otherUser = $this->makeUser(true);

        Notification::factory()->create(['notifiable_user_id' => $user->id, 'title' => 'Own']);
        Notification::factory()->broadcast()->create(['title' => 'Broadcast']);
        Notification::factory()->create(['notifiable_user_id' => $otherUser->id, 'title' => 'Other']);

        Sanctum::actingAs($user, [], 'admin');

        $response = $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Own', $titles);
        $this->assertContains('Broadcast', $titles);
        $this->assertNotContains('Other', $titles);
    }

    #[Test]
    public function index_can_filter_by_is_read(): void
    {
        $user = $this->makeUser(true);

        Notification::factory()->create(['notifiable_user_id' => $user->id, 'is_read' => true]);
        Notification::factory()->create(['notifiable_user_id' => $user->id, 'is_read' => false]);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '?is_read=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_read', false);
    }

    #[Test]
    public function index_can_filter_by_type(): void
    {
        $user = $this->makeUser(true);

        Notification::factory()->create(['notifiable_user_id' => $user->id, 'type' => 'backup_failed']);
        Notification::factory()->create(['notifiable_user_id' => $user->id, 'type' => 'suspicious_session']);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '?type=backup_failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'backup_failed');
    }

    #[Test]
    public function index_can_filter_by_date_range(): void
    {
        $user = $this->makeUser(true);

        $old = Notification::factory()->create(['notifiable_user_id' => $user->id]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $recent = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . "?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    #[Test]
    public function index_respects_per_page(): void
    {
        $user = $this->makeUser(true);

        Notification::factory()->count(5)->create(['notifiable_user_id' => $user->id]);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2);
    }

    #[Test]
    public function index_orders_newest_first(): void
    {
        $user = $this->makeUser(true);

        $first = Notification::factory()->create(['notifiable_user_id' => $user->id]);
        $second = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    // ===================== UNREAD COUNT =====================

    #[Test]
    public function unread_count_includes_own_and_broadcast_unread(): void
    {
        $user = $this->makeUser(true);
        $otherUser = $this->makeUser(true);

        Notification::factory()->create(['notifiable_user_id' => $user->id, 'is_read' => false]);
        Notification::factory()->broadcast()->create(['is_read' => false]);
        Notification::factory()->create(['notifiable_user_id' => $user->id, 'is_read' => true]);
        Notification::factory()->create(['notifiable_user_id' => $otherUser->id, 'is_read' => false]);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '/unread-count')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.unread_count', 2);
    }

    // ===================== SHOW =====================

    #[Test]
    public function user_can_view_own_notification(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '/' . $notification->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $notification->id);
    }

    #[Test]
    public function user_can_view_broadcast_notification(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->broadcast()->create();

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '/' . $notification->id)
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);
    }

    #[Test]
    public function user_cannot_view_other_users_notification(): void
    {
        $user = $this->makeUser(true);
        $otherUser = $this->makeUser(true);
        $notification = Notification::factory()->create(['notifiable_user_id' => $otherUser->id]);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '/' . $notification->id)->assertStatus(404);
    }

    #[Test]
    public function show_returns_404_for_missing_notification(): void
    {
        $user = $this->makeUser(true);

        Sanctum::actingAs($user, [], 'admin');

        $this->getJson(self::URL . '/999999')->assertStatus(404);
    }

    // ===================== MARK READ =====================

    #[Test]
    public function user_can_mark_own_notification_as_read(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->create([
            'notifiable_user_id' => $user->id,
            'is_read' => false,
        ]);

        Sanctum::actingAs($user, [], 'admin');

        $this->patchJson(self::URL . '/' . $notification->id . '/read')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => true,
        ]);
    }

    #[Test]
    public function marking_read_is_idempotent(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->create([
            'notifiable_user_id' => $user->id,
            'is_read' => true,
        ]);

        Sanctum::actingAs($user, [], 'admin');

        $this->patchJson(self::URL . '/' . $notification->id . '/read')
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
    }

    #[Test]
    public function user_cannot_mark_other_users_notification_as_read(): void
    {
        $user = $this->makeUser(true);
        $otherUser = $this->makeUser(true);
        $notification = Notification::factory()->create([
            'notifiable_user_id' => $otherUser->id,
            'is_read' => false,
        ]);

        Sanctum::actingAs($user, [], 'admin');

        $this->patchJson(self::URL . '/' . $notification->id . '/read')->assertStatus(404);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => false,
        ]);
    }

    #[Test]
    public function marking_broadcast_notification_as_read_returns_409(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->broadcast()->create(['is_read' => false]);

        Sanctum::actingAs($user, [], 'admin');

        $this->patchJson(self::URL . '/' . $notification->id . '/read')
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => false,
        ]);
    }

    // ===================== READ ALL =====================

    #[Test]
    public function read_all_marks_only_own_unread_notifications(): void
    {
        $user = $this->makeUser(true);
        $otherUser = $this->makeUser(true);

        $ownUnread = Notification::factory()->create(['notifiable_user_id' => $user->id, 'is_read' => false]);
        $broadcast = Notification::factory()->broadcast()->create(['is_read' => false]);
        $otherUnread = Notification::factory()->create(['notifiable_user_id' => $otherUser->id, 'is_read' => false]);

        Sanctum::actingAs($user, [], 'admin');

        $this->patchJson(self::URL . '/read-all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseHas('notifications', ['id' => $ownUnread->id, 'is_read' => true]);
        $this->assertDatabaseHas('notifications', ['id' => $broadcast->id, 'is_read' => false]);
        $this->assertDatabaseHas('notifications', ['id' => $otherUnread->id, 'is_read' => false]);
    }

    // ===================== IMMUTABILITY =====================

    #[Test]
    public function notifications_cannot_be_created_updated_or_deleted_through_api(): void
    {
        $user = $this->makeUser(true);
        $notification = Notification::factory()->create(['notifiable_user_id' => $user->id]);

        Sanctum::actingAs($user, [], 'admin');

        $this->postJson(self::URL, [])->assertStatus(405);
        $this->putJson(self::URL . '/' . $notification->id, [])->assertStatus(405);
        $this->deleteJson(self::URL . '/' . $notification->id)->assertStatus(405);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    // ===================== SUPER ADMIN =====================

    #[Test]
    public function super_admin_only_sees_own_and_broadcast_notifications(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin', 'is_system_role' => true]);
        $superAdmin = User::factory()->create(['role_id' => $superAdminRole->id, 'is_active' => true]);

        $otherUser = $this->makeUser(true);

        Notification::factory()->create(['notifiable_user_id' => $superAdmin->id, 'title' => 'SA own']);
        Notification::factory()->broadcast()->create(['title' => 'Broadcast']);
        Notification::factory()->create(['notifiable_user_id' => $otherUser->id, 'title' => 'Other']);

        Sanctum::actingAs($superAdmin, [], 'admin');

        $response = $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('SA own', $titles);
        $this->assertContains('Broadcast', $titles);
        $this->assertNotContains('Other', $titles);
    }
}