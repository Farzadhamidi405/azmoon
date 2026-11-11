<?php

namespace Tests\Feature\Api\V1;

use App\Models\Faculty;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FacultyApiTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───────────────────────────────────────────────

    private function getOrCreateRole(string $roleName, array $permissions = []): Role
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['is_system_role' => true]
        );

        foreach ($permissions as $perm) {
            [$module, $action] = explode('.', $perm);
            $permission = Permission::firstOrCreate(['module' => $module, 'action' => $action]);

            if (!$role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission->id);
            }
        }

        return $role;
    }

    private function actingAsRole(string $roleName, array $permissions = []): array
    {
        $role = $this->getOrCreateRole($roleName, $permissions);
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
        return $this->actingAsRole('Exam Administrator', [
            'faculties.view', 'faculties.create', 'faculties.update', 'faculties.delete',
        ]);
    }

    private function questionManager(): array
    {
        return $this->actingAsRole('Question Manager', ['subjects.view']);
    }

    private function labManager(): array
    {
        return $this->actingAsRole('Lab Manager', []);
    }

    // ─── Index ─────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_list_faculties(): void
    {
        [, $token] = $this->superAdmin();

        Faculty::factory()->count(3)->create();

        $this->withToken($token)->getJson('/api/v1/admin/faculties')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'code', 'subjects_count'],
                ],
            ]);
    }

    #[Test]
    public function exam_administrator_can_list_faculties(): void
    {
        [, $token] = $this->examAdmin();

        $this->withToken($token)->getJson('/api/v1/admin/faculties')
            ->assertOk();
    }

    #[Test]
    public function question_manager_cannot_list_faculties(): void
    {
        [, $token] = $this->questionManager();

        $this->withToken($token)->getJson('/api/v1/admin/faculties')
            ->assertForbidden();
    }

    #[Test]
    public function guest_cannot_list_faculties(): void
    {
        $this->getJson('/api/v1/admin/faculties')
            ->assertUnauthorized();
    }

    // ─── Store ─────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_create_faculty(): void
    {
        [, $token] = $this->superAdmin();

        $response = $this->withToken($token)->postJson('/api/v1/admin/faculties', [
            'name' => 'Faculty of Computer Science',
            'code' => 'CS',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'CS');

        $this->assertDatabaseHas('faculties', ['code' => 'CS']);
    }

    #[Test]
    public function create_faculty_fails_without_name(): void
    {
        [, $token] = $this->superAdmin();

        $this->withToken($token)->postJson('/api/v1/admin/faculties', [
            'code' => 'CS',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function create_faculty_fails_with_duplicate_code(): void
    {
        [, $token] = $this->superAdmin();

        Faculty::factory()->create(['code' => 'CS']);

        $this->withToken($token)->postJson('/api/v1/admin/faculties', [
            'name' => 'Another Faculty',
            'code' => 'CS',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function lab_manager_cannot_create_faculty(): void
    {
        [, $token] = $this->labManager();

        $this->withToken($token)->postJson('/api/v1/admin/faculties', [
            'name' => 'Faculty of Law',
            'code' => 'LAW',
        ])->assertForbidden();
    }

    #[Test]
    public function guest_cannot_create_faculty(): void
    {
        $this->postJson('/api/v1/admin/faculties', [
            'name' => 'Faculty of Law',
            'code' => 'LAW',
        ])->assertUnauthorized();
    }

    // ─── Show ──────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_show_faculty(): void
    {
        [, $token] = $this->superAdmin();

        $faculty = Faculty::factory()->create();

        $this->withToken($token)->getJson("/api/v1/admin/faculties/{$faculty->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $faculty->id);
    }

    #[Test]
    public function show_returns_404_for_nonexistent_faculty(): void
    {
        [, $token] = $this->superAdmin();

        $this->withToken($token)->getJson('/api/v1/admin/faculties/99999')
            ->assertNotFound();
    }

    // ─── Update ────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_update_faculty(): void
    {
        [, $token] = $this->superAdmin();

        $faculty = Faculty::factory()->create();

        $this->withToken($token)->putJson("/api/v1/admin/faculties/{$faculty->id}", [
            'name' => 'Updated Faculty Name',
        ])->assertOk()
          ->assertJsonPath('data.name', 'Updated Faculty Name');
    }

    #[Test]
    public function update_fails_with_duplicate_code(): void
    {
        [, $token] = $this->superAdmin();

        Faculty::factory()->create(['code' => 'ENG']);
        $faculty = Faculty::factory()->create(['code' => 'CS']);

        $this->withToken($token)->putJson("/api/v1/admin/faculties/{$faculty->id}", [
            'code' => 'ENG',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function exam_administrator_can_update_faculty(): void
    {
        [, $token] = $this->examAdmin();

        $faculty = Faculty::factory()->create();

        $this->withToken($token)->putJson("/api/v1/admin/faculties/{$faculty->id}", [
            'name' => 'New Name',
        ])->assertOk();
    }

    #[Test]
    public function lab_manager_cannot_update_faculty(): void
    {
        [, $token] = $this->labManager();

        $faculty = Faculty::factory()->create();

        $this->withToken($token)->putJson("/api/v1/admin/faculties/{$faculty->id}", [
            'name' => 'Hacked',
        ])->assertForbidden();
    }

    // ─── Destroy ───────────────────────────────────────────────

    #[Test]
    public function super_admin_can_delete_faculty_without_subjects(): void
    {
        [, $token] = $this->superAdmin();

        $faculty = Faculty::factory()->create();

        $this->withToken($token)->deleteJson("/api/v1/admin/faculties/{$faculty->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('faculties', ['id' => $faculty->id]);
    }

    #[Test]
    public function cannot_delete_faculty_with_subjects(): void
    {
        [, $token] = $this->superAdmin();

        $faculty = Faculty::factory()->create();
        Subject::factory()->create(['faculty_id' => $faculty->id]);

        $this->withToken($token)->deleteJson("/api/v1/admin/faculties/{$faculty->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('faculties', ['id' => $faculty->id]);
    }

    #[Test]
    public function lab_manager_cannot_delete_faculty(): void
    {
        [, $token] = $this->labManager();

        $faculty = Faculty::factory()->create();

        $this->withToken($token)->deleteJson("/api/v1/admin/faculties/{$faculty->id}")
            ->assertForbidden();
    }

    #[Test]
    public function guest_cannot_delete_faculty(): void
    {
        $faculty = Faculty::factory()->create();

        $this->deleteJson("/api/v1/admin/faculties/{$faculty->id}")
            ->assertUnauthorized();
    }
}