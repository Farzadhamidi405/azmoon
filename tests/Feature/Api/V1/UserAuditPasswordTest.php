<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Tests\TestCase;

/**
 * Verifies that raw passwords never reach audit_logs (UserController update/store).
 * Failure labels: LEAK/MISSING = real finding; PRECONDITION = test setup problem.
 * A DB guard runs after app bootstrap and BEFORE RefreshDatabase; it aborts the process on any mismatch.
 */
class UserAuditPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OldPass-1234';
    private const NEW_PASSWORD = 'Zx9RawLeakProbe7431';

    private User $superAdmin;
    private string $token = '';

    // ─── Guard ──────────────────────────────────────────────────

    /** Runs after app bootstrap, before RefreshDatabase: verifies the FINAL runtime config. */
    protected function setUpTraits()
    {
        $this->assertSafeEnvironment();

        return parent::setUpTraits();
    }

    /** Aborts the whole process unless env=testing, no config cache, default mysql connection on azmoon_test. */
    private function assertSafeEnvironment(): void
    {
        $problems = [];

        if ($this->app->environment() !== 'testing' || config('app.env') !== 'testing') {
            $problems[] = 'app.env is not testing';
        }
        if ($this->app->configurationIsCached() || is_file($this->app->getCachedConfigPath())) {
            $problems[] = 'config cache is present';
        }

        $default = (string) config('database.default');
        $dbName  = (string) config("database.connections.{$default}.database");
        $driver  = (string) config("database.connections.{$default}.driver");
        $host    = (string) config("database.connections.{$default}.host");

        if ($default !== 'mysql' || $driver !== 'mysql') {
            $problems[] = "default connection is '{$default}' / driver '{$driver}'";
        }
        if ($dbName !== 'azmoon_test') {
            $problems[] = "final database name is '{$dbName}'";
        }
        if ($host === '') {
            $problems[] = 'database host is empty';
        }

        if ($problems !== []) {
            fwrite(STDERR, "\nGUARD_FAIL: " . implode(' | ', $problems) . "\n");
            exit(99);
        }
    }

    // ─── Setup / helpers ────────────────────────────────────────

    /** Seeds real roles/permissions, then creates a Super Admin with an API token. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->superAdmin = $this->makeUserWithRole('Super Admin');
        $this->token      = $this->makeToken($this->superAdmin);
    }

    /** Creates an active user with the given (seeded) role. */
    private function makeUserWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->first();
        if ($role === null) {
            $this->fail("PRECONDITION: role '{$roleName}' missing after seeding");
        }

        return User::factory()->create(array_merge(['role_id' => $role->id, 'is_active' => true], $overrides));
    }

    /** Creates a Sanctum token (same approach as the 5a authorization test). */
    private function makeToken(User $user): string
    {
        return $user->createToken('pw-audit-test')->plainTextToken;
    }

    /** Sends an authenticated JSON request as the Super Admin. */
    private function sendRequest(string $method, string $uri, array $payload = [])
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ])->json($method, $uri, $payload);
    }

    /** Target user whose password gets changed (known old password). */
    private function makeTarget(): User
    {
        return $this->makeUserWithRole('Invigilator', ['password' => self::OLD_PASSWORD]);
    }

    /** Full update payload that keeps unique fields unchanged. */
    private function fullPayload(User $target, array $extra = []): array
    {
        return array_merge([
            'name'      => 'Edited Name',
            'username'  => $target->username,
            'email'     => $target->email,
            'role_id'   => $target->role_id,
            'is_active' => true,
        ], $extra);
    }

    /** PUT /users/{id}; any non-200 is a setup problem, not a finding. */
    private function updateUser(User $target, array $payload)
    {
        $response = $this->sendRequest('PUT', '/api/v1/admin/users/' . $target->id, $payload);
        if ($response->getStatusCode() !== 200) {
            $this->fail('PRECONDITION: update returned ' . $response->getStatusCode() . ' ' . substr($response->getContent(), 0, 300));
        }

        return $response;
    }

    /** Latest User:updated audit row for the target (raw DB row). */
    private function updatedAuditRow(User $target): object
    {
        $row = DB::table('audit_logs')->where('action', 'User:updated')->where('target_id', $target->id)->orderByDesc('id')->first();
        if ($row === null) {
            $this->fail('PRECONDITION: no User:updated audit row found');
        }

        return $row;
    }

    /** Whole audit table serialized (to search for the raw password anywhere). */
    private function allAuditText(): string
    {
        return DB::table('audit_logs')->get()->toJson();
    }

    // ─── Tests ──────────────────────────────────────────────────

    /** T1: password change works (hash updated, old password invalid). */
    public function test_t1_password_change_works(): void
    {
        $target = $this->makeTarget();
        $this->updateUser($target, $this->fullPayload($target, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]));

        $hash = $target->fresh()->password;
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $hash), 'new password must work');
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $hash), 'old password must no longer work');
    }

    /** T2: raw password string / password key must not be in audit_logs. */
    public function test_t2_audit_does_not_store_raw_password(): void
    {
        $target = $this->makeTarget();
        $this->updateUser($target, $this->fullPayload($target, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]));

        $row = $this->updatedAuditRow($target);
        $new = json_decode((string) $row->new_value, true) ?? [];
        $old = json_decode((string) $row->old_value, true) ?? [];

        $leaks = [];
        if (str_contains($this->allAuditText(), self::NEW_PASSWORD)) { $leaks[] = 'raw password string found in audit_logs'; }
        foreach (['password', 'password_confirmation'] as $key) {
            if (array_key_exists($key, $new)) { $leaks[] = "key '{$key}' in new_value"; }
            if (array_key_exists($key, $old)) { $leaks[] = "key '{$key}' in old_value"; }
        }
        if ($leaks !== []) {
            $this->fail('LEAK: ' . implode('; ', $leaks));
        }
        $this->assertTrue(true);
    }

    /** T3: password change is recorded only as password_changed = true. */
    public function test_t3_audit_records_password_changed_flag(): void
    {
        $target = $this->makeTarget();
        $this->updateUser($target, $this->fullPayload($target, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]));

        $new = json_decode((string) $this->updatedAuditRow($target)->new_value, true) ?? [];
        if (($new['password_changed'] ?? null) !== true) {
            $this->fail('MISSING: password_changed=true not found in new_value');
        }
        $this->assertTrue(true);
    }

    /** T4: other changed fields must still be audited (guards against over-sanitizing). */
    public function test_t4_audit_keeps_other_changed_fields(): void
    {
        $target = $this->makeTarget();
        $this->updateUser($target, $this->fullPayload($target, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]));

        $new = json_decode((string) $this->updatedAuditRow($target)->new_value, true) ?? [];
        $this->assertSame('Edited Name', $new['name'] ?? null);
    }

    /** T5: update without password: no password key and no password_changed flag. */
    public function test_t5_update_without_password_has_no_password_flag(): void
    {
        $target = $this->makeTarget();
        $this->updateUser($target, $this->fullPayload($target));

        $new = json_decode((string) $this->updatedAuditRow($target)->new_value, true) ?? [];
        $this->assertArrayNotHasKey('password', $new);
        $this->assertArrayNotHasKey('password_changed', $new);
        $this->assertSame('Edited Name', $new['name'] ?? null);
    }

    /** T6 (regression): creating a user must not put the password in audit_logs. */
    public function test_t6_store_audit_has_no_password(): void
    {
        $role = Role::where('name', 'Invigilator')->first();
        if ($role === null) {
            $this->fail('PRECONDITION: role Invigilator missing after seeding');
        }

        $response = $this->sendRequest('POST', '/api/v1/admin/users', [
            'name'                  => 'Store Probe',
            'username'              => 'store_probe_user',
            'email'                 => 'store_probe@example.test',
            'password'              => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            'role_id'               => $role->id,
            'is_active'             => true,
        ]);
        if ($response->getStatusCode() !== 201) {
            $this->fail('PRECONDITION: store returned ' . $response->getStatusCode() . ' ' . substr($response->getContent(), 0, 300));
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'User:created']);
        if (str_contains($this->allAuditText(), self::NEW_PASSWORD)) {
            $this->fail('LEAK: raw password string found in audit_logs after store');
        }
        $this->assertTrue(true);
    }

    /** T7: audit-logs API (show) must not return the raw password. */
    public function test_t7_audit_log_api_response_has_no_password(): void
    {
        $target = $this->makeTarget();
        $this->updateUser($target, $this->fullPayload($target, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]));

        $row      = $this->updatedAuditRow($target);
        $response = $this->sendRequest('GET', '/api/v1/admin/audit-logs/' . $row->id);
        if ($response->getStatusCode() !== 200) {
            $this->markTestSkipped('PRECONDITION: audit-logs show returned ' . $response->getStatusCode());
        }
        if (str_contains($response->getContent(), self::NEW_PASSWORD)) {
            $this->fail('LEAK: audit-logs API response contains the raw password');
        }
        $this->assertTrue(true);
    }
}