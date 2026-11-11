<?php

namespace Tests\Feature\Api\V1;

use App\Models\Exam;
use App\Models\Faculty;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserSubjectAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectApiTest extends TestCase
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
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete', 'subjects.assign_access',
        ]);
    }

    private function questionManager(): array
    {
        return $this->actingAsRole('Question Manager', ['subjects.view']);
    }

    private function reviewer(): array
    {
        return $this->actingAsRole('Reviewer', ['subjects.view']);
    }

    private function labManager(): array
    {
        return $this->actingAsRole('Lab Manager', []);
    }

    // ─── Index ─────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_list_all_subjects(): void
    {
        [, $token] = $this->superAdmin();

        Subject::factory()->count(3)->create();

        $this->withToken($token)->getJson('/api/v1/admin/subjects')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function exam_administrator_can_list_all_subjects(): void
    {
        [, $token] = $this->examAdmin();

        Subject::factory()->count(2)->create();

        $this->withToken($token)->getJson('/api/v1/admin/subjects')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function question_manager_only_sees_assigned_subjects(): void
    {
        [$user, $token] = $this->questionManager();

        $assigned = Subject::factory()->create();
        Subject::factory()->create(); // not assigned

        UserSubjectAccess::factory()->create([
            'user_id'    => $user->id,
            'subject_id' => $assigned->id,
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/subjects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
    }

    #[Test]
    public function reviewer_only_sees_assigned_subjects(): void
    {
        [$user, $token] = $this->reviewer();

        $assigned = Subject::factory()->create();
        Subject::factory()->create();

        UserSubjectAccess::factory()->create([
            'user_id'    => $user->id,
            'subject_id' => $assigned->id,
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/subjects')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function lab_manager_cannot_list_subjects(): void
    {
        [, $token] = $this->labManager();

        $this->withToken($token)->getJson('/api/v1/admin/subjects')
            ->assertForbidden();
    }

    #[Test]
    public function guest_cannot_list_subjects(): void
    {
        $this->getJson('/api/v1/admin/subjects')
            ->assertUnauthorized();
    }

    // ─── Store ─────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_create_subject(): void
    {
        [, $token] = $this->superAdmin();

        $faculty = Faculty::factory()->create();

        $response = $this->withToken($token)->postJson('/api/v1/admin/subjects', [
            'name'       => 'English Language',
            'code'       => 'EN',
            'faculty_id' => $faculty->id,
            'is_active'  => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'EN');

        $this->assertDatabaseHas('subjects', ['code' => 'EN']);
    }

    #[Test]
    public function create_subject_fails_without_name(): void
    {
        [, $token] = $this->superAdmin();

        $this->withToken($token)->postJson('/api/v1/admin/subjects', [
            'code' => 'EN',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function create_subject_fails_with_duplicate_code(): void
    {
        [, $token] = $this->superAdmin();

        Subject::factory()->create(['code' => 'EN']);

        $this->withToken($token)->postJson('/api/v1/admin/subjects', [
            'name' => 'English',
            'code' => 'EN',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function create_subject_fails_with_invalid_faculty(): void
    {
        [, $token] = $this->superAdmin();

        $this->withToken($token)->postJson('/api/v1/admin/subjects', [
            'name'       => 'Math',
            'code'       => 'MATH',
            'faculty_id' => 99999,
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['faculty_id']);
    }

    #[Test]
    public function question_manager_cannot_create_subject(): void
    {
        [, $token] = $this->questionManager();

        $this->withToken($token)->postJson('/api/v1/admin/subjects', [
            'name' => 'Math',
            'code' => 'MATH',
        ])->assertForbidden();
    }

    // ─── Show ──────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_show_any_subject(): void
    {
        [, $token] = $this->superAdmin();

        $subject = Subject::factory()->create();

        $this->withToken($token)->getJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $subject->id);
    }

    #[Test]
    public function question_manager_can_view_assigned_subject(): void
    {
        [$user, $token] = $this->questionManager();

        $subject = Subject::factory()->create();

        UserSubjectAccess::factory()->create([
            'user_id'    => $user->id,
            'subject_id' => $subject->id,
        ]);

        $this->withToken($token)->getJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertOk();
    }

    #[Test]
    public function question_manager_cannot_view_unassigned_subject(): void
    {
        [, $token] = $this->questionManager();

        $subject = Subject::factory()->create();

        $this->withToken($token)->getJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertForbidden();
    }

    #[Test]
    public function show_returns_404_for_nonexistent_subject(): void
    {
        [, $token] = $this->superAdmin();

        $this->withToken($token)->getJson('/api/v1/admin/subjects/99999')
            ->assertNotFound();
    }

    // ─── Update ────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_update_subject(): void
    {
        [, $token] = $this->superAdmin();

        $subject = Subject::factory()->create();

        $this->withToken($token)->putJson("/api/v1/admin/subjects/{$subject->id}", [
            'name' => 'Updated Subject Name',
        ])->assertOk()
          ->assertJsonPath('data.name', 'Updated Subject Name');
    }

    #[Test]
    public function super_admin_can_deactivate_subject(): void
    {
        [, $token] = $this->superAdmin();

        $subject = Subject::factory()->create(['is_active' => true]);

        $this->withToken($token)->putJson("/api/v1/admin/subjects/{$subject->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('subjects', [
            'id'        => $subject->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function update_fails_with_duplicate_code(): void
    {
        [, $token] = $this->superAdmin();

        Subject::factory()->create(['code' => 'MATH']);
        $subject = Subject::factory()->create(['code' => 'EN']);

        $this->withToken($token)->putJson("/api/v1/admin/subjects/{$subject->id}", [
            'code' => 'MATH',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function question_manager_cannot_update_subject(): void
    {
        [$user, $token] = $this->questionManager();

        $subject = Subject::factory()->create();

        UserSubjectAccess::factory()->create([
            'user_id'    => $user->id,
            'subject_id' => $subject->id,
        ]);

        $this->withToken($token)->putJson("/api/v1/admin/subjects/{$subject->id}", [
            'name' => 'Hacked',
        ])->assertForbidden();
    }

    // ─── Destroy ───────────────────────────────────────────────

    #[Test]
    public function super_admin_can_delete_subject_without_dependencies(): void
    {
        [, $token] = $this->superAdmin();

        $subject = Subject::factory()->create();

        $this->withToken($token)->deleteJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
    }

    #[Test]
    public function cannot_delete_subject_with_exams(): void
    {
        [, $token] = $this->superAdmin();

        $subject = Subject::factory()->create();
        Exam::factory()->create(['subject_id' => $subject->id]);

        $this->withToken($token)->deleteJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    #[Test]
    public function cannot_delete_subject_with_questions(): void
    {
        [, $token] = $this->superAdmin();

        $subject = Subject::factory()->create();
        Question::factory()->create(['subject_id' => $subject->id]);

        $this->withToken($token)->deleteJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    #[Test]
    public function question_manager_cannot_delete_subject(): void
    {
        [, $token] = $this->questionManager();

        $subject = Subject::factory()->create();

        $this->withToken($token)->deleteJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertForbidden();
    }

    #[Test]
    public function guest_cannot_delete_subject(): void
    {
        $subject = Subject::factory()->create();

        $this->deleteJson("/api/v1/admin/subjects/{$subject->id}")
            ->assertUnauthorized();
    }
}