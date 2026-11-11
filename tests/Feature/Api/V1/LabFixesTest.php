<?php

namespace Tests\Feature\Api\V1;

use App\Models\Computer;
use App\Models\Lab;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backend fixes for Labs: Form Requests (store/update), computer counters,
 * computers-grid endpoint and audit_logs columns.
 * Uses the real roles/permissions (RoleSeeder + PermissionSeeder) and Sanctum tokens.
 */
class LabFixesTest extends TestCase
{
    use RefreshDatabase;

    /** Base URI of the lab endpoints. */
    private const BASE = '/api/v1/admin/labs';

    /**
     * Runs before any DB trait work: aborts unless env/database/config cache are the test ones.
     */
    protected function setUpTraits()
    {
        $this->assertSafeEnvironment();

        return parent::setUpTraits();
    }

    /**
     * Stops the whole process (exit 99) if env, database or config cache are not the test ones.
     */
    private function assertSafeEnvironment(): void
    {
        $problems = [];
        $conn = (string) config('database.default');
        $db = (string) config("database.connections.{$conn}.database");

        if (app()->environment() !== 'testing') {
            $problems[] = 'APP_ENV=' . app()->environment();
        }
        if ($conn !== 'mysql') {
            $problems[] = 'DB_CONNECTION=' . $conn;
        }
        if ($db !== 'azmoon_test') {
            $problems[] = 'DB_DATABASE=' . $db;
        }
        if (DB::connection()->getDatabaseName() !== 'azmoon_test') {
            $problems[] = 'connection database=' . DB::connection()->getDatabaseName();
        }
        if (app()->configurationIsCached()) {
            $problems[] = 'config cache present';
        }

        if ($problems !== []) {
            fwrite(STDERR, 'GUARD_FAIL: ' . implode(' | ', $problems) . PHP_EOL);
            exit(99);
        }
    }

    /**
     * Seeds the real roles and permissions.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    /**
     * Creates a user with the given seeded role and authenticates the next requests with a Sanctum token.
     */
    private function loginAs(string $roleName): User
    {
        $role = Role::where('name', $roleName)->first();
        $this->assertNotNull($role, "Role '{$roleName}' is missing after seeding");
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    /**
     * Creates a lab with 6 computers: 2 online, 1 offline, 1 exam, 1 maintenance, 1 locked.
     */
    private function makeLabWithComputers(): Lab
    {
        $lab = Lab::factory()->create();
        foreach (['online', 'online', 'offline', 'exam', 'maintenance', 'locked'] as $i => $status) {
            Computer::factory()->create([
                'lab_id' => $lab->id,
                'status' => $status,
                'computer_code' => "FX-{$lab->id}-{$i}",
            ]);
        }

        return $lab;
    }

    /**
     * Returns the latest audit row of an action.
     */
    private function auditRow(string $action): ?object
    {
        return DB::table('audit_logs')->where('action', $action)->orderByDesc('id')->first();
    }

    // --- Store ---

    public function test_01_store_creates_lab_and_returns_201(): void
    {
        $this->loginAs('Super Admin');
        $res = $this->postJson(self::BASE, ['name' => 'Lab A', 'location' => 'Floor 1']);
        $res->assertStatus(201);
        $this->assertTrue((bool) $res->json('success'));
        $this->assertSame('Lab A', $res->json('data.name'));
        $this->assertDatabaseHas('labs', ['name' => 'Lab A', 'location' => 'Floor 1']);
    }

    public function test_02_store_without_location_is_allowed(): void
    {
        $this->loginAs('Super Admin');
        $res = $this->postJson(self::BASE, ['name' => 'Lab B']);
        $res->assertStatus(201);
        $this->assertNull($res->json('data.location'));
    }

    public function test_03_store_without_name_is_422(): void
    {
        $this->loginAs('Super Admin');
        $this->postJson(self::BASE, ['location' => 'Floor 1'])->assertStatus(422);
        $this->assertSame(0, Lab::count());
    }

    public function test_04_store_name_longer_than_100_is_422(): void
    {
        $this->loginAs('Super Admin');
        $this->postJson(self::BASE, ['name' => str_repeat('a', 101)])->assertStatus(422);
        $this->assertSame(0, Lab::count());
    }

    public function test_05_store_location_longer_than_255_is_422(): void
    {
        $this->loginAs('Super Admin');
        $this->postJson(self::BASE, ['name' => 'Lab C', 'location' => str_repeat('b', 256)])->assertStatus(422);
        $this->assertSame(0, Lab::count());
    }

    public function test_06_store_by_invigilator_is_403(): void
    {
        $this->loginAs('Invigilator');
        $this->postJson(self::BASE, ['name' => 'Lab D'])->assertStatus(403);
        $this->assertSame(0, Lab::count());
    }

    // --- Update ---

    public function test_07_update_name_only_keeps_location(): void
    {
        $this->loginAs('Super Admin');
        $lab = Lab::factory()->create(['name' => 'Before', 'location' => 'Floor 2']);
        $this->putJson(self::BASE . '/' . $lab->id, ['name' => 'After'])->assertStatus(200);
        $lab->refresh();
        $this->assertSame('After', $lab->name);
        $this->assertSame('Floor 2', $lab->location);
    }

    public function test_08_update_location_null_keeps_name(): void
    {
        $this->loginAs('Super Admin');
        $lab = Lab::factory()->create(['name' => 'Keep', 'location' => 'Floor 3']);
        $this->putJson(self::BASE . '/' . $lab->id, ['location' => null])->assertStatus(200);
        $lab->refresh();
        $this->assertSame('Keep', $lab->name);
        $this->assertNull($lab->location);
    }

    public function test_09_update_empty_name_is_422(): void
    {
        $this->loginAs('Super Admin');
        $lab = Lab::factory()->create(['name' => 'Stay']);
        $this->putJson(self::BASE . '/' . $lab->id, ['name' => ''])->assertStatus(422);
        $this->assertSame('Stay', $lab->fresh()->name);
    }

    public function test_10_update_by_invigilator_is_403(): void
    {
        $this->loginAs('Invigilator');
        $lab = Lab::factory()->create(['name' => 'Locked']);
        $this->putJson(self::BASE . '/' . $lab->id, ['name' => 'Hacked'])->assertStatus(403);
        $this->assertSame('Locked', $lab->fresh()->name);
    }

    // --- Counters ---

    public function test_11_index_returns_status_counters(): void
    {
        $this->loginAs('Super Admin');
        $lab = $this->makeLabWithComputers();
        $res = $this->getJson(self::BASE);
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id', $lab->id);
        $this->assertNotNull($row, 'lab row missing in index');
        $this->assertEquals(6, $row['computers_count']);
        $this->assertEquals(2, $row['online_count']);
        $this->assertEquals(1, $row['offline_count']);
        $this->assertEquals(1, $row['exam_count']);
        $this->assertEquals(1, $row['maintenance_count']);
        $this->assertEquals(1, $row['locked_count']);
    }

    public function test_12_show_returns_status_counters(): void
    {
        $this->loginAs('Super Admin');
        $lab = $this->makeLabWithComputers();
        $res = $this->getJson(self::BASE . '/' . $lab->id);
        $res->assertStatus(200);
        $this->assertEquals(6, $res->json('data.computers_count'));
        $this->assertEquals(2, $res->json('data.online_count'));
        $this->assertEquals(1, $res->json('data.locked_count'));
    }

    // --- Computers grid ---

    public function test_13_computers_grid_contract(): void
    {
        $this->loginAs('Super Admin');
        $lab = $this->makeLabWithComputers();
        $res = $this->getJson(self::BASE . '/' . $lab->id . '/computers-grid');
        $res->assertStatus(200);
        $this->assertTrue((bool) $res->json('success'));
        $this->assertEquals($lab->id, $res->json('data.lab.id'));
        $this->assertSame($lab->name, $res->json('data.lab.name'));
        $this->assertEquals(
            ['total' => 6, 'online' => 2, 'offline' => 1, 'exam' => 1, 'maintenance' => 1, 'locked' => 1],
            $res->json('data.summary')
        );
        $this->assertCount(6, $res->json('data.computers'));
        $this->assertSame("FX-{$lab->id}-0", $res->json('data.computers.0.computer_code'));
    }

    public function test_14_computers_grid_unknown_lab_is_404(): void
    {
        $this->loginAs('Super Admin');
        $this->getJson(self::BASE . '/999999/computers-grid')->assertStatus(404);
    }

    // --- Audit log ---

    public function test_15_store_writes_audit_row(): void
    {
        $user = $this->loginAs('Super Admin');
        $res = $this->postJson(self::BASE, ['name' => 'Audit Lab', 'location' => 'Room 7']);
        $res->assertStatus(201);
        $row = $this->auditRow('lab.created');
        $this->assertNotNull($row, 'audit row lab.created not written');
        $this->assertSame('labs', $row->module);
        $this->assertNull($row->old_value);
        $this->assertEquals(['name' => 'Audit Lab', 'location' => 'Room 7'], json_decode($row->new_value, true));
        $this->assertSame((int) $res->json('data.id'), (int) $row->target_id);
        $this->assertSame($user->id, (int) $row->user_id);
    }

    public function test_16_update_writes_audit_row_with_old_and_new(): void
    {
        $this->loginAs('Super Admin');
        $lab = Lab::factory()->create(['name' => 'Before', 'location' => 'Loc']);
        $this->putJson(self::BASE . '/' . $lab->id, ['name' => 'After'])->assertStatus(200);
        $row = $this->auditRow('lab.updated');
        $this->assertNotNull($row, 'audit row lab.updated not written');
        $this->assertSame('labs', $row->module);
        $this->assertEquals(['name' => 'Before'], json_decode($row->old_value, true));
        $this->assertEquals(['name' => 'After'], json_decode($row->new_value, true));
        $this->assertSame($lab->id, (int) $row->target_id);
    }

    public function test_17_destroy_writes_audit_row(): void
    {
        $this->loginAs('Exam Administrator');
        $lab = Lab::factory()->create(['name' => 'ToDelete']);
        $this->deleteJson(self::BASE . '/' . $lab->id)->assertSuccessful();
        $row = $this->auditRow('lab.deleted');
        $this->assertNotNull($row, 'audit row lab.deleted not written');
        $this->assertSame('labs', $row->module);
        $this->assertEquals(['name' => 'ToDelete'], json_decode($row->old_value, true));
        $this->assertNull($row->new_value);
        $this->assertSame($lab->id, (int) $row->target_id);
    }
}
