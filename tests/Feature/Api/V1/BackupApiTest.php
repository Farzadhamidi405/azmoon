<?php

namespace Tests\Feature\Api\V1;

use App\Models\Backup;
use App\Models\Role;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/backups';

    private function makeUser(bool $superAdmin = false): User
    {
        $roleName = $superAdmin ? 'Super Admin' : ('Test Role ' . uniqid());

        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['is_system_role' => $superAdmin]
        );

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    // ─── Auth (Guest) ──────────────────────────────────────

    public function test_guest_cannot_list_backups(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_guest_cannot_create_backup(): void
    {
        $this->postJson(self::URL)->assertStatus(401);
    }

    public function test_guest_cannot_show_backup(): void
    {
        $backup = Backup::factory()->create();

        $this->getJson(self::URL . '/' . $backup->id)->assertStatus(401);
    }

    public function test_guest_cannot_download_backup(): void
    {
        $backup = Backup::factory()->create(['status' => 'completed']);

        $this->getJson(self::URL . '/' . $backup->id . '/download')->assertStatus(401);
    }

    public function test_guest_cannot_delete_backup(): void
    {
        $backup = Backup::factory()->create();

        $this->deleteJson(self::URL . '/' . $backup->id)->assertStatus(401);
    }

    // ─── Permissions (Non Super Admin) ───────────────────────

    public function test_non_super_admin_gets_403_on_index(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'admin')
            ->getJson(self::URL)
            ->assertStatus(403);
    }

    public function test_non_super_admin_gets_403_on_store(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'admin')
            ->postJson(self::URL)
            ->assertStatus(403);
    }

    public function test_non_super_admin_gets_403_on_show(): void
    {
        $user = $this->makeUser();
        $backup = Backup::factory()->create();

        $this->actingAs($user, 'admin')
            ->getJson(self::URL . '/' . $backup->id)
            ->assertStatus(403);
    }

    public function test_non_super_admin_gets_403_on_download(): void
    {
        $user = $this->makeUser();
        $backup = Backup::factory()->create(['status' => 'completed']);

        $this->actingAs($user, 'admin')
            ->getJson(self::URL . '/' . $backup->id . '/download')
            ->assertStatus(403);
    }

    public function test_non_super_admin_gets_403_on_destroy(): void
    {
        $user = $this->makeUser();
        $backup = Backup::factory()->create();

        $this->actingAs($user, 'admin')
            ->deleteJson(self::URL . '/' . $backup->id)
            ->assertStatus(403);
    }

    // ─── Index ────────────────────────────────────────────────

    public function test_super_admin_can_list_backups(): void
    {
        $admin = $this->makeUser(true);
        Backup::factory()->count(3)->create();

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'backup_type', 'status', 'triggered_by']]]);
    }

    public function test_super_admin_can_filter_backups_by_status(): void
    {
        $admin = $this->makeUser(true);
        Backup::factory()->create(['status' => 'completed']);
        Backup::factory()->create(['status' => 'failed']);

        $response = $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?status=failed')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('failed', $data[0]['status']);
    }

    public function test_index_rejects_invalid_status_filter(): void
    {
        $admin = $this->makeUser(true);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '?status=not-a-real-status')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // ─── Show ─────────────────────────────────────────────────

    public function test_super_admin_can_view_single_backup(): void
    {
        $admin = $this->makeUser(true);
        $backup = Backup::factory()->create();

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $backup->id)
            ->assertOk()
            ->assertJsonPath('data.id', $backup->id);
    }

    public function test_show_returns_404_for_missing_backup(): void
    {
        $admin = $this->makeUser(true);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/999999')
            ->assertStatus(404);
    }

    public function test_backup_resource_does_not_expose_sensitive_fields(): void
    {
        $admin = $this->makeUser(true);
        $backup = Backup::factory()->create([
            'file_path' => 'backups/secret.zip',
            'download_token' => 'abc123',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $backup->id)
            ->assertOk();

        $data = $response->json('data');
        $this->assertArrayNotHasKey('file_path', $data);
        $this->assertArrayNotHasKey('download_token', $data);
        $this->assertArrayNotHasKey('download_expires_at', $data);
        $this->assertArrayNotHasKey('email_sent_at', $data);
        $this->assertArrayNotHasKey('email_error', $data);
    }

    // ─── Store ────────────────────────────────────────────────

    public function test_super_admin_can_create_backup_successfully(): void
    {
        $admin = $this->makeUser(true);

        $backup = Backup::factory()->create([
            'status' => 'completed',
            'backup_type' => 'full',
            'triggered_by' => 'manual',
            'initiated_by' => $admin->id,
        ]);

        $this->mock(BackupService::class, function ($mock) use ($backup, $admin) {
            $mock->shouldReceive('createFullBackup')
                ->once()
                ->with($admin->id)
                ->andReturn($backup);
        });

        $this->actingAs($admin, 'admin')
            ->postJson(self::URL)
            ->assertStatus(201)
            ->assertJsonPath('data.id', $backup->id)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_create_backup_failure_returns_generic_error_without_leaking_details(): void
    {
        $admin = $this->makeUser(true);

        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('createFullBackup')
                ->once()
                ->andThrow(new \RuntimeException('mysqldump failed: super-secret-detail'));
        });

        $response = $this->actingAs($admin, 'admin')
            ->postJson(self::URL)
            ->assertStatus(500);

        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString('super-secret-detail', $response->getContent());
    }

    public function test_creating_backup_logs_audit(): void
    {
        $admin = $this->makeUser(true);
        $backup = Backup::factory()->create(['status' => 'completed']);

        $this->mock(BackupService::class, function ($mock) use ($backup) {
            $mock->shouldReceive('createFullBackup')->once()->andReturn($backup);
        });

        $this->actingAs($admin, 'admin')
            ->postJson(self::URL)
            ->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'backups',
            'action' => 'Backup:created',
            'target_type' => 'Backup',
            'target_id' => $backup->id,
        ]);
    }

    // ─── Download ─────────────────────────────────────────────

    public function test_super_admin_can_download_completed_backup(): void
    {
        $admin = $this->makeUser(true);
        $disk = config('backup.disk');
        Storage::fake($disk);

        $relativePath = config('backup.directory') . '/test-backup.zip';
        Storage::disk($disk)->put($relativePath, 'fake-zip-content');

        $backup = Backup::factory()->create([
            'status' => 'completed',
            'file_path' => $relativePath,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(self::URL . '/' . $backup->id . '/download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    public function test_downloading_backup_logs_audit(): void
    {
        $admin = $this->makeUser(true);
        $disk = config('backup.disk');
        Storage::fake($disk);

        $relativePath = config('backup.directory') . '/test-backup.zip';
        Storage::disk($disk)->put($relativePath, 'fake-zip-content');

        $backup = Backup::factory()->create([
            'status' => 'completed',
            'file_path' => $relativePath,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(self::URL . '/' . $backup->id . '/download')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'backups',
            'action' => 'Backup:downloaded',
            'target_type' => 'Backup',
            'target_id' => $backup->id,
        ]);
    }

    public function test_download_rejects_non_completed_backup(): void
    {
        $admin = $this->makeUser(true);
        $backup = Backup::factory()->create(['status' => 'failed', 'file_path' => null]);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $backup->id . '/download')
            ->assertStatus(422);
    }

    public function test_download_rejects_file_outside_backup_directory(): void
    {
        $admin = $this->makeUser(true);
        $disk = config('backup.disk');
        Storage::fake($disk);

        // اطمینان از وجود فیزیکی پوشهٔ backups
        Storage::disk($disk)->makeDirectory(config('backup.directory'));

        // فایلی خارج از پوشهٔ مجاز backups ساخته می‌شود
        Storage::disk($disk)->put('secret-outside.txt', 'top-secret');

        $backup = Backup::factory()->create([
            'status' => 'completed',
            'file_path' => 'secret-outside.txt',
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $backup->id . '/download')
            ->assertStatus(404);
    }

    public function test_download_returns_404_when_file_missing_from_disk(): void
    {
        $admin = $this->makeUser(true);
        $disk = config('backup.disk');
        Storage::fake($disk);

        $backup = Backup::factory()->create([
            'status' => 'completed',
            'file_path' => config('backup.directory') . '/missing.zip',
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(self::URL . '/' . $backup->id . '/download')
            ->assertStatus(404);
    }

    // ─── Destroy ──────────────────────────────────────────────

    public function test_super_admin_can_delete_backup_with_file(): void
    {
        $admin = $this->makeUser(true);
        $disk = config('backup.disk');
        Storage::fake($disk);

        $relativePath = config('backup.directory') . '/to-delete.zip';
        Storage::disk($disk)->put($relativePath, 'content');

        $backup = Backup::factory()->create([
            'status' => 'completed',
            'file_path' => $relativePath,
        ]);

        $this->actingAs($admin, 'admin')
            ->deleteJson(self::URL . '/' . $backup->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk($disk)->assertMissing($relativePath);
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    public function test_delete_succeeds_even_if_file_already_missing(): void
    {
        $admin = $this->makeUser(true);
        $disk = config('backup.disk');
        Storage::fake($disk);

        $backup = Backup::factory()->create([
            'status' => 'completed',
            'file_path' => config('backup.directory') . '/already-gone.zip',
        ]);

        $this->actingAs($admin, 'admin')
            ->deleteJson(self::URL . '/' . $backup->id)
            ->assertOk();

        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    public function test_deleting_backup_logs_audit(): void
    {
        $admin = $this->makeUser(true);
        $backup = Backup::factory()->create(['status' => 'completed', 'file_path' => null]);

        $this->actingAs($admin, 'admin')
            ->deleteJson(self::URL . '/' . $backup->id)
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'backups',
            'action' => 'Backup:deleted',
            'target_type' => 'Backup',
            'target_id' => $backup->id,
        ]);
    }

    // ─── Method Not Allowed ───────────────────────────────────

    public function test_update_method_not_allowed_on_collection(): void
    {
        $admin = $this->makeUser(true);

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL, ['foo' => 'bar'])
            ->assertStatus(405);
    }

    public function test_update_method_not_allowed_on_single_resource(): void
    {
        $admin = $this->makeUser(true);
        $backup = Backup::factory()->create();

        $this->actingAs($admin, 'admin')
            ->putJson(self::URL . '/' . $backup->id, ['foo' => 'bar'])
            ->assertStatus(405);
    }
}