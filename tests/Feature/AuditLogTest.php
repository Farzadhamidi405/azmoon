<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تست‌های Step 12.7 — Audit Logs API.
 * ⚠️ فرض: factory برای User وجود دارد و Role رابطه permissions() دارد.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/audit-logs';

    /** ساخت کاربر ادمین با/بدون permission مربوط به audit.view */
    private function makeAdmin(bool $withAuditView): User
    {
        $role = Role::create([
            'name'           => 'Test Role ' . uniqid(),
            'is_system_role' => false,
        ]);

        if ($withAuditView) {
            $permission = Permission::firstOrCreate(['module' => 'audit', 'action' => 'view']);
            $role->permissions()->attach($permission->id);
        }

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /** ساخت یک رکورد لاگ با امکان override فیلدها */
    private function makeLog(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'user_id'     => null,
            'role'        => 'Exam Administrator',
            'action'      => 'Updated',
            'module'      => 'exams',
            'target_type' => 'Exam',
            'target_id'   => 1,
            'old_value'   => ['pass_marks' => 50],
            'new_value'   => ['pass_marks' => 60],
            'ip_address'  => '10.10.1.2',
            'device'      => 'Desktop',
            'user_agent'  => 'PHPUnit',
        ], $overrides));
    }

    public function test_guest_cannot_list_audit_logs(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_user_without_permission_gets_403_on_index(): void
    {
        $admin = $this->makeAdmin(false);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL)
            ->assertStatus(403);
    }

    public function test_user_without_permission_gets_403_on_show(): void
    {
        $admin = $this->makeAdmin(false);
        $log = $this->makeLog();

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $log->id)
            ->assertStatus(403);
    }

    public function test_user_with_permission_can_list_logs_with_pagination(): void
    {
        $admin = $this->makeAdmin(true);
        $this->makeLog();
        $this->makeLog(['action' => 'Created']);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'role', 'action', 'module', 'target_type', 'target_id',
                    'old_value', 'new_value', 'ip_address', 'device', 'created_at']],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_index_does_not_expose_user_agent_but_show_does(): void
    {
        $admin = $this->makeAdmin(true);
        $log = $this->makeLog(['user_agent' => 'Mozilla/Test']);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonMissingPath('data.0.user_agent');

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $log->id)
            ->assertOk()
            ->assertJsonPath('data.user_agent', 'Mozilla/Test');
    }

    public function test_logs_are_ordered_newest_first(): void
    {
        $admin = $this->makeAdmin(true);
        $first = $this->makeLog(['action' => 'First']);
        $second = $this->makeLog(['action' => 'Second']);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_can_filter_by_module(): void
    {
        $admin = $this->makeAdmin(true);
        $this->makeLog(['module' => 'exams']);
        $this->makeLog(['module' => 'questions']);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?module=questions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.module', 'questions');
    }

    public function test_can_filter_by_action(): void
    {
        $admin = $this->makeAdmin(true);
        $this->makeLog(['action' => 'Created']);
        $this->makeLog(['action' => 'Deleted']);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?action=Deleted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'Deleted');
    }

    public function test_can_filter_by_user_id_and_includes_user_summary(): void
    {
        $admin = $this->makeAdmin(true);
        $actor = User::factory()->create();
        $this->makeLog(['user_id' => $actor->id]);
        $this->makeLog(['user_id' => null]);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?user_id=' . $actor->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $actor->id);
    }

    public function test_can_filter_by_target_and_ip(): void
    {
        $admin = $this->makeAdmin(true);
        $this->makeLog(['target_type' => 'Exam', 'target_id' => 7, 'ip_address' => '10.0.0.7']);
        $this->makeLog(['target_type' => 'Question', 'target_id' => 9, 'ip_address' => '10.0.0.9']);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?target_type=Question&target_id=9&ip_address=10.0.0.9')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_id', 9);
    }

    public function test_can_filter_by_date_range(): void
    {
        $admin = $this->makeAdmin(true);

        $old = $this->makeLog(['action' => 'Old']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $recent = $this->makeLog(['action' => 'Recent']);

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . "?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_per_page_is_respected(): void
    {
        $admin = $this->makeAdmin(true);
        foreach (range(1, 5) as $i) {
            $this->makeLog(['target_id' => $i]);
        }

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_validation_rejects_bad_filters(): void
    {
        $admin = $this->makeAdmin(true);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?per_page=1000&ip_address=not-an-ip&date_from=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page', 'ip_address', 'date_from']);
    }

    public function test_validation_rejects_date_to_before_date_from(): void
    {
        $admin = $this->makeAdmin(true);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?date_from=2025-05-10&date_to=2025-05-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_show_returns_full_details_with_old_and_new_values(): void
    {
        $admin = $this->makeAdmin(true);
        $log = $this->makeLog();

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $log->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.old_value.pass_marks', 50)
            ->assertJsonPath('data.new_value.pass_marks', 60);
    }

    public function test_show_returns_404_for_missing_log(): void
    {
        $admin = $this->makeAdmin(true);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/999999')
            ->assertStatus(404);
    }

    public function test_audit_logs_are_immutable_through_api(): void
    {
        $admin = $this->makeAdmin(true);
        $log = $this->makeLog();

        // هیچ route نوشتن/حذف نباید وجود داشته باشد → 405 Method Not Allowed
        $this->actingAs($admin, 'admin')->postJson(self::URL, [])->assertStatus(405);
        $this->actingAs($admin, 'admin')->putJson(self::URL . '/' . $log->id, [])->assertStatus(405);
        $this->actingAs($admin, 'admin')->deleteJson(self::URL . '/' . $log->id)->assertStatus(405);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }
}