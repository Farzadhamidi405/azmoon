<?php

namespace Tests\Feature\Api\V1;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserSubjectAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectAccessApiTest extends TestCase
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
            'subjects.view', 'subjects.assign_access',
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
    public function super_admin_can_list_subject_access(): void
    {
        [, $token] = $this->superAdmin();
        [$qm]      = $this->questionManager();

        $subject = Subject::factory()->create();

        UserSubjectAccess::factory()->create([
            'user_id'    => $qm->id,
            'subject_id' => $subject->id,
        ]);

        $this->withToken($token)->getJson("/api/v1/admin/subjects/{$subject->id}/access")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ─── Store ─────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_grant_subject_access_to_question_manager(): void
    {
        [, $token] = $this->superAdmin();
        [$qm]      = $this->questionManager();

        $subject = Subject::factory()->create();

        $response = $this->withToken($token)->postJson(
            "/api/v1/admin/subjects/{$subject->id}/access",
            ['user_id' => $qm->id]
        );

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_subject_access', [
            'user_id'    => $qm->id,
            'subject_id' => $subject->id,
        ]);
    }

    #[Test]
    public function super_admin_can_grant_subject_access_to_reviewer(): void
    {
        [, $token] = $this->superAdmin();
        [$reviewerUser] = $this->reviewer();

        $subject = Subject::factory()->create();

        $this->withToken($token)->postJson(
            "/api/v1/admin/subjects/{$subject->id}/access",
            ['user_id' => $reviewerUser->id]
        )->assertCreated();
    }

    #[Test]
    public function exam_administrator_can_grant_subject_access(): void
    {
        [, $token] = $this->examAdmin();
        [$qm]      = $this->questionManager();

        $subject = Subject::factory()->create();

        $this->withToken($token)->postJson(
            "/api/v1/admin/subjects/{$subject->id}/access",
            ['user_id' => $qm->id]
        )->assertCreated();
    }

    #[Test]
    public function granting_access_to_invalid_role_fails(): void
    {
        [, $token] = $this->superAdmin();
        [$labManagerUser] = $this->labManager();

        $subject = Subject::factory()->create();

        $this->withToken($token)->postJson(
            "/api/v1/admin/subjects/{$subject->id}/access",
            ['user_id' => $labManagerUser->id]
        )->assertUnprocessable()
          ->assertJsonValidationErrors(['user_id']);
    }

    #[Test]
    public function granting_duplicate_access_fails(): void
    {
        [, $token] = $this->superAdmin();
        [$qm]      = $this->questionManager();

        $subject = Subject::factory()->create();

        UserSubjectAccess::factory()->create([
            'user_id'    => $qm->id,
            'subject_id' => $subject->id,
        ]);

        $this->withToken($token)->postJson(
            "/api/v1/admin/subjects/{$subject->id}/access",
            ['user_id' => $qm->id]
        )->assertUnprocessable()
          ->assertJsonValidationErrors(['user_id']);
    }

    #[Test]
    public function question_manager_cannot_grant_subject_access(): void
    {
        [$qmActing, $token] = $this->questionManager();
        [$otherQm] = $this->questionManager();

        $subject = Subject::factory()->create();

        UserSubjectAccess::factory()->create([
            'user_id'    => $qmActing->id,
            'subject_id' => $subject->id,
        ]);

        $this->withToken($token)->postJson(
            "/api/v1/admin/subjects/{$subject->id}/access",
            ['user_id' => $otherQm->id]
        )->assertForbidden();
    }

    #[Test]
    public function guest_cannot_grant_subject_access(): void
    {
        [$qm] = $this->questionManager();
        $subject = Subject::factory()->create();

        $this->postJson(
            "/api/v1/admin/subjects/{$subject->id}/access",
            ['user_id' => $qm->id]
        )->assertUnauthorized();
    }

    // ─── Destroy ───────────────────────────────────────────────

    #[Test]
    public function super_admin_can_revoke_subject_access(): void
    {
        [, $token] = $this->superAdmin();
        [$qm]      = $this->questionManager();

        $subject = Subject::factory()->create();

        $access = UserSubjectAccess::factory()->create([
            'user_id'    => $qm->id,
            'subject_id' => $subject->id,
        ]);

        $this->withToken($token)->deleteJson(
            "/api/v1/admin/subjects/{$subject->id}/access/{$access->id}"
        )->assertOk()
          ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('user_subject_access', ['id' => $access->id]);
    }

    #[Test]
    public function revoking_access_with_mismatched_subject_returns_404(): void
    {
        [, $token] = $this->superAdmin();
        [$qm]      = $this->questionManager();

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();

        $access = UserSubjectAccess::factory()->create([
            'user_id'    => $qm->id,
            'subject_id' => $subjectA->id,
        ]);

        $this->withToken($token)->deleteJson(
            "/api/v1/admin/subjects/{$subjectB->id}/access/{$access->id}"
        )->assertNotFound();
    }

    #[Test]
    public function question_manager_cannot_revoke_subject_access(): void
    {
        [$qmActing, $token] = $this->questionManager();

        $subject = Subject::factory()->create();

        $access = UserSubjectAccess::factory()->create([
            'user_id'    => $qmActing->id,
            'subject_id' => $subject->id,
        ]);

        $this->withToken($token)->deleteJson(
            "/api/v1/admin/subjects/{$subject->id}/access/{$access->id}"
        )->assertForbidden();
    }

    #[Test]
    public function guest_cannot_revoke_subject_access(): void
    {
        [$qm] = $this->questionManager();
        $subject = Subject::factory()->create();

        $access = UserSubjectAccess::factory()->create([
            'user_id'    => $qm->id,
            'subject_id' => $subject->id,
        ]);

        $this->deleteJson(
            "/api/v1/admin/subjects/{$subject->id}/access/{$access->id}"
        )->assertUnauthorized();
    }
}