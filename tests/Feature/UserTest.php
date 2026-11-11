<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function actingAsRole(string $roleName): array
    {
        $role = Role::factory()->create(['name' => $roleName]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
    }

    private function superAdmin(): array
    {
        return $this->actingAsRole('Super Admin');
    }

    private function examAdmin(): array
    {
        return $this->actingAsRole('Exam Administrator');
    }

    private function labManager(): array
    {
        return $this->actingAsRole('Lab Manager');
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_list_users(): void
    {
        [, $token] = $this->superAdmin();

        $role = Role::factory()->create(['name' => 'Reviewer']);
        User::factory()->count(3)->create(['role_id' => $role->id]);

        $response = $this->withToken($token)->getJson('/api/v1/admin/users');

        $response->assertOk()
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => ['id', 'name', 'username', 'email', 'role_id', 'is_active'],
                    ],
                ]);
    }

    #[Test]
    public function exam_administrator_cannot_list_users(): void
    {
        [, $token] = $this->examAdmin();

        $this->withToken($token)->getJson('/api/v1/admin/users')
             ->assertForbidden();
    }

    #[Test]
    public function guest_cannot_list_users(): void
    {
        $this->getJson('/api/v1/admin/users')
             ->assertUnauthorized();
    }

    // ─── Store ─────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_create_user(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Question Manager']);

        $payload = [
            'name'                  => 'Ahmad Wali',
            'username'              => 'a.wali',
            'email'                 => 'a.wali@kunduz.edu.af',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id'               => $role->id,
            'is_active'             => true,
        ];

        $response = $this->withToken($token)->postJson('/api/v1/admin/users', $payload);

        $response->assertCreated()
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.username', 'a.wali');

        $this->assertDatabaseHas('users', ['username' => 'a.wali']);
    }

    #[Test]
    public function password_is_hashed_on_create(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name'                  => 'Test User',
            'username'              => 'test.user',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id'               => $role->id,
        ])->assertCreated();

        $user = User::where('username', 'test.user')->first();
        $this->assertNotEquals('Password@123', $user->password);
    }

    #[Test]
    public function username_must_be_unique(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);
        User::factory()->create(['username' => 'duplicate', 'role_id' => $role->id]);

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name'                  => 'Another User',
            'username'              => 'duplicate',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id'               => $role->id,
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['username']);
    }

    #[Test]
    public function name_is_required(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'username'              => 'no.name',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id'               => $role->id,
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function role_id_must_exist(): void
    {
        [, $token] = $this->superAdmin();

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name'                  => 'Ghost User',
            'username'              => 'ghost.user',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id'               => 99999,
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['role_id']);
    }

    #[Test]
    public function lab_manager_cannot_create_user(): void
    {
        [, $token] = $this->labManager();
        $role = Role::factory()->create(['name' => 'Reviewer']);

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name'                  => 'Test',
            'username'              => 'test.lab',
            'password'              => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id'               => $role->id,
        ])->assertForbidden();
    }

    // ─── Show ──────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_show_user(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);
        $target = User::factory()->create(['role_id' => $role->id]);

        $this->withToken($token)->getJson("/api/v1/admin/users/{$target->id}")
             ->assertOk()
             ->assertJsonPath('data.id', $target->id);
    }

    #[Test]
    public function show_returns_404_for_nonexistent_user(): void
    {
        [, $token] = $this->superAdmin();

        $this->withToken($token)->getJson('/api/v1/admin/users/99999')
             ->assertNotFound();
    }

    // ─── Update ────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_update_user(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);
        $target = User::factory()->create(['role_id' => $role->id]);

        $this->withToken($token)->putJson("/api/v1/admin/users/{$target->id}", [
            'name'    => 'Updated Name',
            'role_id' => $role->id,
        ])->assertOk()
          ->assertJsonPath('data.name', 'Updated Name');
    }

    #[Test]
    public function super_admin_can_deactivate_user(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);
        $target = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->withToken($token)->putJson("/api/v1/admin/users/{$target->id}", [
            'is_active' => false,
            'role_id'   => $role->id,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id'        => $target->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function exam_administrator_cannot_update_user(): void
    {
        [, $token] = $this->examAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);
        $target = User::factory()->create(['role_id' => $role->id]);

        $this->withToken($token)->putJson("/api/v1/admin/users/{$target->id}", [
            'name'    => 'Hacked',
            'role_id' => $role->id,
        ])->assertForbidden();
    }

    // ─── Destroy ───────────────────────────────────────────────────────────────

   #[Test]
    public function super_admin_can_delete_user(): void
    {
        [, $token] = $this->superAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);
        $target = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->withToken($token)->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // destroy در این سیستم کاربر را غیرفعال می‌کند نه حذف
        $this->assertDatabaseHas('users', [
            'id'        => $target->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function exam_administrator_cannot_delete_user(): void
    {
        [, $token] = $this->examAdmin();
        $role = Role::factory()->create(['name' => 'Reviewer']);
        $target = User::factory()->create(['role_id' => $role->id]);

        $this->withToken($token)->deleteJson("/api/v1/admin/users/{$target->id}")
             ->assertForbidden();
    }

    #[Test]
    public function guest_cannot_delete_user(): void
    {
        $role = Role::factory()->create(['name' => 'Reviewer']);
        $target = User::factory()->create(['role_id' => $role->id]);

        $this->deleteJson("/api/v1/admin/users/{$target->id}")
             ->assertUnauthorized();
    }
}