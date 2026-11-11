<?php

namespace Tests\Feature\Api\V1;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/settings';

    private function makeUser(array $permissions = [], bool $superAdmin = false): User
    {
        $roleName = $superAdmin ? 'Super Admin' : ('Test Role ' . uniqid());

        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['is_system_role' => $superAdmin]
        );

        foreach ($permissions as $perm) {
            [$module, $action] = explode('.', $perm);
            $permission = Permission::firstOrCreate(['module' => $module, 'action' => $action]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    // ─── Auth ──────────────────────────────────────────────

    public function test_guest_cannot_list_settings(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_guest_cannot_show_setting(): void
    {
        $this->getJson(self::URL . '/exam.default_calendar')->assertStatus(401);
    }

    public function test_guest_cannot_update_setting(): void
    {
        $this->putJson(self::URL . '/exam.default_calendar', ['value' => 'x'])->assertStatus(401);
    }

    // ─── Permissions ───────────────────────────────────────

    public function test_user_without_permission_gets_403_on_index(): void
    {
        $user = $this->makeUser([]);

        $this->actingAs($user, 'admin')
            ->getJson(self::URL)
            ->assertStatus(403);
    }

    public function test_user_without_permission_gets_403_on_show(): void
    {
        $user = $this->makeUser([]);
        Setting::factory()->create(['key' => 'exam.default_calendar']);

        $this->actingAs($user, 'admin')
            ->getJson(self::URL . '/exam.default_calendar')
            ->assertStatus(403);
    }

    public function test_user_with_view_only_cannot_update(): void
    {
        $user = $this->makeUser(['settings.view']);

        $this->actingAs($user, 'admin')
            ->putJson(self::URL . '/exam.default_calendar', ['value' => 'x'])
            ->assertStatus(403);
    }

    public function test_user_with_view_can_list_and_show(): void
    {
        $user = $this->makeUser(['settings.view']);
        Setting::factory()->create(['key' => 'exam.default_calendar', 'value' => 'persian']);

        $this->actingAs($user, 'admin')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'key', 'value', 'updated_by']]]);

        $this->actingAs($user, 'admin')
            ->getJson(self::URL . '/exam.default_calendar')
            ->assertOk()
            ->assertJsonPath('data.key', 'exam.default_calendar')
            ->assertJsonPath('data.value', 'persian');
    }

    // ─── Super Admin bypass ──────────────────────────────────

    public function test_super_admin_can_create_new_setting(): void
    {
        $admin = $this->makeUser([], superAdmin: true);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/backup.admin_emails', ['value' => 'a@test.com'])
            ->assertOk()
            ->assertJsonPath('data.key', 'backup.admin_emails')
            ->assertJsonPath('data.value', 'a@test.com');

        $this->assertDatabaseHas('settings', [
            'key'   => 'backup.admin_emails',
            'value' => 'a@test.com',
        ]);
    }

    public function test_super_admin_can_update_existing_setting(): void
    {
        $admin = $this->makeUser([], superAdmin: true);
        Setting::factory()->create(['key' => 'exam.default_calendar', 'value' => 'persian']);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/exam.default_calendar', ['value' => 'gregorian'])
            ->assertOk()
            ->assertJsonPath('data.value', 'gregorian');

        $this->assertDatabaseHas('settings', [
            'key'   => 'exam.default_calendar',
            'value' => 'gregorian',
        ]);
    }

    // ─── Validation ──────────────────────────────────────────

    public function test_update_rejects_invalid_key_format(): void
    {
        $admin = $this->makeUser([], superAdmin: true);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/Invalid-Key!', ['value' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_update_rejects_missing_value_field(): void
    {
        $admin = $this->makeUser([], superAdmin: true);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/exam.default_calendar', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    public function test_update_accepts_null_value(): void
    {
        $admin = $this->makeUser([], superAdmin: true);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/exam.default_calendar', ['value' => null])
            ->assertOk()
            ->assertJsonPath('data.value', null);
    }

    // ─── 404 ──────────────────────────────────────────────────

    public function test_show_returns_404_for_missing_key(): void
    {
        $admin = $this->makeUser(['settings.view']);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/does.not.exist')
            ->assertStatus(404);
    }

    // ─── Audit Log ──────────────────────────────────────────

    public function test_creating_setting_logs_audit_with_null_old_value(): void
    {
        $admin = $this->makeUser([], superAdmin: true);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/backup.admin_emails', ['value' => 'a@test.com'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'module'      => 'settings',
            'action'      => 'Created',
            'target_type' => 'Setting',
        ]);

        $log = \App\Models\AuditLog::where('module', 'settings')->where('action', 'Created')->first();
        $this->assertNull($log->old_value);
        $this->assertEquals(['value' => 'a@test.com'], $log->new_value);
    }

    public function test_updating_setting_logs_audit_with_old_and_new_value(): void
    {
        $admin = $this->makeUser([], superAdmin: true);
        Setting::factory()->create(['key' => 'exam.default_calendar', 'value' => 'persian']);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/exam.default_calendar', ['value' => 'gregorian'])
            ->assertOk();

        $log = \App\Models\AuditLog::where('module', 'settings')->where('action', 'Updated')->first();
        $this->assertNotNull($log);
        $this->assertEquals(['value' => 'persian'], $log->old_value);
        $this->assertEquals(['value' => 'gregorian'], $log->new_value);
    }

    // ─── Method not allowed (no delete endpoint) ────────────

    public function test_delete_method_not_allowed(): void
    {
        $admin = $this->makeUser([], superAdmin: true);
        Setting::factory()->create(['key' => 'exam.default_calendar']);

        $this->actingAs($admin, 'admin')
            ->deleteJson(self::URL . '/exam.default_calendar')
            ->assertStatus(405);
    }
}
