<?php

namespace Tests\Feature\Api\V1;

use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Lab;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Role matrix for Lab / Computer / Shift endpoints (including computers-grid and auto-assign).
 * Roles and permissions come from the real RoleSeeder and PermissionSeeder.
 * Forbidden calls must return 403 and must not change the database.
 * Allowed calls must pass authorization (business validation results are out of scope here).
 */
class LabComputerShiftAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Auto-assign route URI (taken from route:list when this file was generated). */
    private const AUTO_ASSIGN_URI = 'api/v1/admin/labs/shifts/{shift}/auto-assign';

    /** Permission action required by each endpoint (all map to labs.<action>). */
    private const ENDPOINT_PERMISSION = [
        'labs.index'          => 'view',
        'labs.show'           => 'view',
        'labs.computers_grid' => 'view',
        'labs.store'          => 'create',
        'labs.update'         => 'update',
        'labs.destroy'        => 'delete',
        'computers.index'     => 'view',
        'computers.show'      => 'view',
        'computers.store'     => 'create',
        'computers.update'    => 'update',
        'computers.status'    => 'update',
        'computers.destroy'   => 'delete',
        'shifts.index'        => 'view',
        'shifts.show'         => 'view',
        'shifts.store'        => 'create',
        'shifts.update'       => 'update',
        'shifts.destroy'      => 'delete',
        'auto_assign.preview' => 'update',
        'auto_assign.final'   => 'update',
    ];

    /** Expected labs.* actions per staff role (hard-coded expectation, independent of the seeder). */
    private const ROLE_PERMISSIONS = [
        'Super Admin'        => ['view', 'create', 'update', 'delete'],
        'Exam Administrator' => ['view', 'create', 'update', 'delete'],
        'Lab Manager'        => ['view', 'create', 'update'],
        'Invigilator'        => ['view'],
        'Question Manager'   => [],
        'Reviewer'           => [],
        'Result Reviewer'    => [],
    ];

    /** Seed the real roles and permissions into the test database. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    /** Data provider: every staff role against every endpoint with the expected outcome. */
    public static function roleMatrix(): array
    {
        $cases = [];
        foreach (self::ROLE_PERMISSIONS as $role => $actions) {
            foreach (self::ENDPOINT_PERMISSION as $endpoint => $action) {
                $cases["$role | $endpoint"] = [$role, $endpoint, in_array($action, $actions, true)];
            }
        }
        return $cases;
    }

    /** Data provider: all endpoints (for the unauthenticated test). */
    public static function endpoints(): array
    {
        $cases = [];
        foreach (array_keys(self::ENDPOINT_PERMISSION) as $endpoint) {
            $cases[$endpoint] = [$endpoint];
        }
        return $cases;
    }

    /** Create a user with the given seeded role and return a Sanctum token. */
    private function makeToken(string $roleName): string
    {
        $role = Role::where('name', $roleName)->first();
        $this->assertNotNull($role, "Role '$roleName' is missing after seeding");
        $user = User::factory()->create(['role_id' => $role->id]);
        return $user->createToken('test')->plainTextToken;
    }

    /** Build the records used by the endpoints (explicit lab_id everywhere). */
    private function makeFixtures(): array
    {
        $lab      = Lab::factory()->create();
        $computer = Computer::factory()->create(['lab_id' => $lab->id]);
        $shift    = Shift::factory()->create(['lab_id' => $lab->id]);
        // Unassigned candidate in this shift: final auto-assign would set computer_id on it.
        $assignment = CandidateAssignment::factory()->create([
            'exam_id'     => $shift->exam_id,
            'shift_id'    => $shift->id,
            'lab_id'      => $lab->id,
            'computer_id' => null,
        ]);
        // Deletable records (no children, no assignments).
        $emptyLab     = Lab::factory()->create();
        $otherLab     = Lab::factory()->create();
        $freeComputer = Computer::factory()->create(['lab_id' => $otherLab->id]);
        $freeShift    = Shift::factory()->create(['lab_id' => $otherLab->id]);

        return [
            'lab' => $lab->id, 'computer' => $computer->id, 'shift' => $shift->id,
            'exam' => $shift->exam_id, 'assignment' => $assignment->id,
            'emptyLab' => $emptyLab->id, 'freeComputer' => $freeComputer->id, 'freeShift' => $freeShift->id,
        ];
    }

    /** Serialize the affected tables to detect any write. */
    private function dbSnapshot(): string
    {
        $out = [];
        foreach (['labs', 'computers', 'shifts', 'candidate_assignments'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return json_encode($out);
    }

    /** Send the request for an endpoint key (token null means unauthenticated). */
    private function sendRequest(string $endpoint, ?string $token, array $f): TestResponse
    {
        $b = '/api/v1/admin';
        $c = $token === null ? $this : $this->withToken($token);
        $autoAssign = '/' . ltrim(str_replace('{shift}', (string) $f['shift'], self::AUTO_ASSIGN_URI), '/');

        return match ($endpoint) {
            'labs.index'          => $c->getJson("$b/labs"),
            'labs.show'           => $c->getJson("$b/labs/{$f['lab']}"),
            'labs.computers_grid' => $c->getJson("$b/labs/{$f['lab']}/computers-grid"),
            'labs.store'          => $c->postJson("$b/labs", ['name' => 'Auth Test Lab', 'location' => 'Block B']),
            'labs.update'         => $c->putJson("$b/labs/{$f['lab']}", ['name' => 'Renamed Lab']),
            'labs.destroy'        => $c->deleteJson("$b/labs/{$f['emptyLab']}"),
            'computers.index'     => $c->getJson("$b/computers"),
            'computers.show'      => $c->getJson("$b/computers/{$f['computer']}"),
            'computers.store'     => $c->postJson("$b/computers", [
                'lab_id' => $f['lab'],
                'computer_code' => 'PC-AUTH-1',
                'ip_address' => '10.0.0.5',
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
            ]),
            'computers.update'    => $c->putJson("$b/computers/{$f['computer']}", ['ip_address' => '10.0.0.77']),
            'computers.status'    => $c->patchJson("$b/computers/{$f['computer']}/status", ['status' => 'maintenance']),
            'computers.destroy'   => $c->deleteJson("$b/computers/{$f['freeComputer']}"),
            'shifts.index'        => $c->getJson("$b/shifts"),
            'shifts.show'         => $c->getJson("$b/shifts/{$f['shift']}"),
            'shifts.store'        => $c->postJson("$b/shifts", [
                'exam_id' => $f['exam'],
                'name' => 'Auth Shift',
                'start_time' => '2030-01-01 08:00:00',
                'end_time' => '2030-01-01 10:00:00',
                'lab_id' => $f['lab'],
                'capacity' => 10,
            ]),
            'shifts.update'       => $c->putJson("$b/shifts/{$f['shift']}", ['name' => 'Renamed Shift']),
            'shifts.destroy'      => $c->deleteJson("$b/shifts/{$f['freeShift']}"),
            'auto_assign.preview' => $c->postJson($autoAssign, ['lab_id' => $f['lab']]),
            'auto_assign.final'   => $c->postJson($autoAssign, ['lab_id' => $f['lab'], 'confirmed' => true]),
        };
    }

    #[Test]
    #[DataProvider('roleMatrix')]
    public function role_matrix_is_enforced(string $role, string $endpoint, bool $allowed): void
    {
        $token = $this->makeToken($role);
        $f = $this->makeFixtures();
        $before = $this->dbSnapshot();

        $response = $this->sendRequest($endpoint, $token, $f);

        if ($allowed) {
            // Authorization must pass; 422/404 from business rules are not this test's concern.
            $this->assertNotSame(401, $response->getStatusCode(), "$role should be authenticated on $endpoint");
            $this->assertNotSame(403, $response->getStatusCode(), "$role should be authorized on $endpoint");
            $readOnly = ['labs.index', 'labs.show', 'labs.computers_grid', 'computers.index', 'computers.show', 'shifts.index', 'shifts.show'];
            if (in_array($endpoint, $readOnly, true)) {
                $response->assertOk();
            }
            return;
        }

        $response->assertForbidden();
        // A forbidden call must not change anything.
        $this->assertSame($before, $this->dbSnapshot(), "$role changed data on forbidden $endpoint");
        if ($endpoint === 'auto_assign.final') {
            $this->assertDatabaseHas('candidate_assignments', ['id' => $f['assignment'], 'computer_id' => null]);
        }
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function unauthenticated_requests_get_401(string $endpoint): void
    {
        $f = $this->makeFixtures();
        $this->sendRequest($endpoint, null, $f)->assertUnauthorized();
    }
}