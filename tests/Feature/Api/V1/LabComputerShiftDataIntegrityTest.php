<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attempt;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Exam;
use App\Models\Lab;
use App\Models\SessionTransfer;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Data-integrity tests for Lab / Computer / Shift controllers (Phase 14.6.5b).
 * Authorization is covered by LabComputerShiftAuthorizationTest, so Gate::before(true) is used here.
 * Tests named *_current lock behavior that must NOT change; the others describe the target behavior.
 */
class LabComputerShiftDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ─── Guard ────────────────────────────────────────────────

    /**
     * Runs after the app is booted and before any DB trait work: aborts unless the FINAL config is safe.
     */
    protected function setUpTraits()
    {
        $this->assertSafeEnvironment();

        return parent::setUpTraits();
    }

    /**
     * Stops the whole process (exit 99) if env / database / config cache are not the test ones.
     */
    private function assertSafeEnvironment(): void
    {
        $problems = [];
        $conn = (string) config('database.default');
        $db   = (string) config("database.connections.{$conn}.database");

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

    // ─── Setup / helpers ──────────────────────────────────────

    /**
     * Logs in a Super Admin on the admin guard and bypasses policies (covered by the authorization test).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeAdmin(), 'admin');
    }

    /**
     * Creates a staff user; attaches the Super Admin role when the users.role_id column exists.
     */
    private function makeAdmin(): User
    {
        $attrs = [];
        $roleClass = 'App\\Models\\Role';

        if (Schema::hasColumn('users', 'role_id') && class_exists($roleClass)) {
            $role = $roleClass::query()->where('name', 'Super Admin')->first()
                ?? $roleClass::forceCreate(['name' => 'Super Admin']);
            $attrs['role_id'] = $role->id;
        }

        return User::factory()->create($attrs);
    }

    /**
     * Sends a JSON request (named to avoid clashing with Laravel TestCase methods).
     */
    private function sendRequest(string $method, string $uri, array $data = [])
    {
        return $this->json($method, $uri, $data);
    }

    /**
     * Finds the real update verb (PUT or PATCH) of a route from the router, no guessing.
     */
    private function resolveUpdateMethod(string $uriPattern): string
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uriPattern) {
                foreach (['PUT', 'PATCH'] as $m) {
                    if (in_array($m, $route->methods(), true)) {
                        return $m;
                    }
                }
            }
        }

        $this->fail("HARNESS: no PUT/PATCH route found for {$uriPattern}");
    }

    /**
     * Asserts the HTTP status with a label so the failure names the gap number.
     */
    private function expectStatus($res, int $code, string $label): void
    {
        $this->assertSame(
            $code,
            $res->getStatusCode(),
            "{$label}: expected HTTP {$code}, got {$res->getStatusCode()}"
        );
    }

    /**
     * Creates a computer inside the given lab.
     */
    private function makeComputer(Lab $lab, array $o = []): Computer
    {
        return Computer::factory()->create(array_merge(['lab_id' => $lab->id], $o));
    }

    /**
     * Creates a shift (own exam and lab unless overridden).
     */
    private function makeShift(array $o = []): Shift
    {
        return Shift::factory()->create($o);
    }

    /**
     * Creates one assignment of a fresh candidate into the shift (optionally with a computer).
     */
    private function makeAssignment(Shift $shift, ?Computer $computer = null): CandidateAssignment
    {
        return CandidateAssignment::factory()->create([
            'shift_id'    => $shift->id,
            'exam_id'     => $shift->exam_id,
            'lab_id'      => $shift->lab_id,
            'computer_id' => $computer?->id,
        ]);
    }

    /**
     * Formats "now + N hours" as a UTC datetime string.
     */
    private function ts(int $hours): string
    {
        return now()->addHours($hours)->format('Y-m-d H:i:s');
    }

    /**
     * Reads the summary block of a computers-grid response.
     */
    private function gridSummary($res): array
    {
        return (array) ($res->json('data.summary') ?? $res->json('summary') ?? []);
    }

    // ─── Harness ──────────────────────────────────────────────

    public function test_00_harness_smoke_admin_can_list_labs(): void
    {
        $res = $this->getJson('/api/v1/admin/labs');
        $this->expectStatus($res, 200, 'HARNESS');
        $this->assertTrue((bool) $res->json('success'), 'HARNESS: success flag missing');
    }

    // ─── Lab ──────────────────────────────────────────────────

    public function test_01_lab_delete_with_offline_computer_is_422(): void
    {
        $lab = Lab::factory()->create();
        $this->makeComputer($lab, ['status' => 'offline']);

        $res = $this->deleteJson('/api/v1/admin/labs/' . $lab->id);

        $this->expectStatus($res, 422, 'BUG#3 lab delete with computer');
        $this->assertDatabaseHas('labs', ['id' => $lab->id]);
    }

    public function test_02_lab_delete_with_only_shift_is_422(): void
    {
        $lab = Lab::factory()->create();
        $this->makeShift(['lab_id' => $lab->id]);

        $res = $this->deleteJson('/api/v1/admin/labs/' . $lab->id);

        $this->expectStatus($res, 422, 'BUG#3 lab delete with shift');
        $this->assertDatabaseHas('labs', ['id' => $lab->id]);
    }

    public function test_03_lab_delete_with_only_assignment_is_422(): void
    {
        $lab = Lab::factory()->create();
        $otherShift = $this->makeShift();
        CandidateAssignment::factory()->create([
            'shift_id'    => $otherShift->id,
            'exam_id'     => $otherShift->exam_id,
            'lab_id'      => $lab->id,
            'computer_id' => null,
        ]);

        $res = $this->deleteJson('/api/v1/admin/labs/' . $lab->id);

        $this->expectStatus($res, 422, 'BUG#3 lab delete with assignment');
        $this->assertDatabaseHas('labs', ['id' => $lab->id]);
    }

    public function test_04_lab_delete_with_online_computer_is_422_current(): void
    {
        $lab = Lab::factory()->create();
        $this->makeComputer($lab, ['status' => 'online']);

        $res = $this->deleteJson('/api/v1/admin/labs/' . $lab->id);

        $this->expectStatus($res, 422, 'CURRENT active-computer block');
        $this->assertFalse((bool) $res->json('success'));
        $this->assertDatabaseHas('labs', ['id' => $lab->id]);
    }

    public function test_05_empty_lab_delete_succeeds_current(): void
    {
        $lab = Lab::factory()->create();

        $res = $this->deleteJson('/api/v1/admin/labs/' . $lab->id);

        $this->expectStatus($res, 200, 'CURRENT empty lab delete');
        $this->assertDatabaseMissing('labs', ['id' => $lab->id]);
    }

    public function test_06_probe_fk_violation_code_is_mysql_1451(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName(), 'PROBE: driver is not mysql');

        $lab = Lab::factory()->create();
        $this->makeComputer($lab, ['status' => 'offline']);

        try {
            $lab->delete();
            $this->fail('PROBE: deleting a lab with a computer did not throw');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) ($e->errorInfo[0] ?? ''), 'PROBE: SQLSTATE');
            $this->assertSame(1451, (int) ($e->errorInfo[1] ?? 0), 'PROBE: driver error code');
        }
    }

    public function test_07_lab_index_has_locked_count(): void
    {
        $lab = Lab::factory()->create();
        $this->makeComputer($lab, ['status' => 'online']);
        $this->makeComputer($lab, ['status' => 'locked']);
        $this->makeComputer($lab, ['status' => 'offline']);

        $res = $this->getJson('/api/v1/admin/labs');
        $this->expectStatus($res, 200, 'index');

        $row = collect($res->json('data'))->firstWhere('id', $lab->id);
        $this->assertNotNull($row, 'lab row missing in index');
        $this->assertSame(1, $row['locked_count'] ?? 'MISSING', 'BUG#9 locked_count in index');
    }

    public function test_08_lab_show_has_locked_count(): void
    {
        $lab = Lab::factory()->create();
        $this->makeComputer($lab, ['status' => 'locked']);
        $this->makeComputer($lab, ['status' => 'online']);

        $res = $this->getJson('/api/v1/admin/labs/' . $lab->id);
        $this->expectStatus($res, 200, 'show');

        $this->assertSame(1, $res->json('data.locked_count') ?? 'MISSING', 'BUG#9 locked_count in show');
    }

    public function test_09_grid_summary_has_locked(): void
    {
        $lab = Lab::factory()->create();
        foreach (['online', 'exam', 'offline', 'maintenance', 'locked'] as $st) {
            $this->makeComputer($lab, ['status' => $st]);
        }

        $res = $this->getJson('/api/v1/admin/labs/' . $lab->id . '/computers-grid');
        $this->expectStatus($res, 200, 'grid');

        $summary = $this->gridSummary($res);
        $this->assertSame(1, $summary['locked'] ?? 'MISSING', 'BUG#9 summary.locked');
    }

    public function test_10_grid_summary_parts_sum_to_total(): void
    {
        $lab = Lab::factory()->create();
        foreach (['online', 'exam', 'offline', 'maintenance', 'locked'] as $st) {
            $this->makeComputer($lab, ['status' => $st]);
        }

        $res = $this->getJson('/api/v1/admin/labs/' . $lab->id . '/computers-grid');
        $this->expectStatus($res, 200, 'grid');

        $s = $this->gridSummary($res);
        $sum = (int) ($s['online'] ?? 0) + (int) ($s['exam'] ?? 0) + (int) ($s['offline'] ?? 0)
             + (int) ($s['maintenance'] ?? 0) + (int) ($s['locked'] ?? 0);
        $this->assertSame((int) ($s['total'] ?? -1), $sum, 'BUG#9 status parts do not add up to total');
    }

    // ─── Computer: index ──────────────────────────────────────

    public function test_11_computer_search_respects_lab_filter(): void
    {
        $labA = Lab::factory()->create();
        $labB = Lab::factory()->create();
        $a = $this->makeComputer($labA, ['computer_code' => 'ZQX-A', 'hostname' => 'zqxhost-a', 'ip_address' => '10.80.0.1']);
        $this->makeComputer($labB, ['computer_code' => 'ZQX-B', 'hostname' => 'zqxhost-b', 'ip_address' => '10.80.0.2']);

        $res = $this->getJson('/api/v1/admin/computers?' . http_build_query(['lab_id' => $labA->id, 'search' => 'zqx']));
        $this->expectStatus($res, 200, 'index');

        $ids = collect($res->json('data.data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$a->id], $ids, 'BUG#4 search bypassed the lab_id filter');
    }

    public function test_12_computer_search_respects_status_filter(): void
    {
        $lab = Lab::factory()->create();
        $on = $this->makeComputer($lab, ['status' => 'online', 'computer_code' => 'ZQS-1', 'hostname' => 'zqshost-1', 'ip_address' => '10.81.0.1']);
        $this->makeComputer($lab, ['status' => 'offline', 'computer_code' => 'ZQS-2', 'hostname' => 'zqshost-2', 'ip_address' => '10.81.0.2']);

        $res = $this->getJson('/api/v1/admin/computers?' . http_build_query(['status' => 'online', 'search' => 'zqs']));
        $this->expectStatus($res, 200, 'index');

        $ids = collect($res->json('data.data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$on->id], $ids, 'BUG#4 search bypassed the status filter');
    }

    public function test_13_computer_search_finds_by_code_hostname_ip_current(): void
    {
        $lab = Lab::factory()->create();
        $byCode = $this->makeComputer($lab, ['computer_code' => 'KQWCODE9', 'hostname' => 'plainhost1', 'ip_address' => '10.1.1.1']);
        $byHost = $this->makeComputer($lab, ['computer_code' => 'PLAIN-2', 'hostname' => 'kqwhost9', 'ip_address' => '10.1.1.2']);
        $byIp   = $this->makeComputer($lab, ['computer_code' => 'PLAIN-3', 'hostname' => 'plainhost3', 'ip_address' => '10.77.7.7']);

        $cases = ['KQWCODE9' => $byCode->id, 'kqwhost9' => $byHost->id, '10.77.7.7' => $byIp->id];
        foreach ($cases as $term => $expectedId) {
            $res = $this->getJson('/api/v1/admin/computers?' . http_build_query(['search' => $term]));
            $this->expectStatus($res, 200, 'CURRENT search ' . $term);
            $ids = collect($res->json('data.data'))->pluck('id')->all();
            $this->assertSame([$expectedId], $ids, 'CURRENT search by ' . $term);
        }
    }

    public function test_14_per_page_above_100_is_capped(): void
    {
        $this->makeComputer(Lab::factory()->create());

        $res = $this->getJson('/api/v1/admin/computers?per_page=1000');

        $this->expectStatus($res, 200, 'per_page=1000');
        $this->assertSame(100, $res->json('data.per_page'), 'BUG#8 per_page cap');
    }

    public function test_15_per_page_default_is_50_current(): void
    {
        $this->makeComputer(Lab::factory()->create());

        $res = $this->getJson('/api/v1/admin/computers');

        $this->expectStatus($res, 200, 'default per_page');
        $this->assertSame(50, $res->json('data.per_page'), 'CURRENT default per_page');
    }

    public function test_16_per_page_100_is_kept_current(): void
    {
        $this->makeComputer(Lab::factory()->create());

        $res = $this->getJson('/api/v1/admin/computers?per_page=100');

        $this->expectStatus($res, 200, 'per_page=100');
        $this->assertSame(100, $res->json('data.per_page'), 'per_page=100 must stay 100');
    }

    public function test_17_per_page_abc_is_clamped_to_1(): void
    {
        $this->makeComputer(Lab::factory()->create());

        $res = $this->getJson('/api/v1/admin/computers?per_page=abc');

        $this->expectStatus($res, 200, 'BUG#8 per_page=abc');
        $this->assertSame(1, $res->json('data.per_page'), 'BUG#8 per_page=abc clamp');
    }

    public function test_18_per_page_zero_is_clamped_to_1(): void
    {
        $this->makeComputer(Lab::factory()->create());

        $res = $this->getJson('/api/v1/admin/computers?per_page=0');

        $this->expectStatus($res, 200, 'BUG#8 per_page=0');
        $this->assertSame(1, $res->json('data.per_page'), 'BUG#8 per_page=0 clamp');
    }

    public function test_19_per_page_negative_is_clamped_to_1(): void
    {
        $this->makeComputer(Lab::factory()->create());

        $res = $this->getJson('/api/v1/admin/computers?per_page=-5');

        $this->expectStatus($res, 200, 'BUG#8 per_page=-5');
        $this->assertSame(1, $res->json('data.per_page'), 'BUG#8 per_page=-5 clamp');
    }

    // ─── Computer: store ──────────────────────────────────────

    public function test_20_store_rejects_status_exam(): void
    {
        $lab = Lab::factory()->create();
        $code = 'NEW-EXAM-1';

        $res = $this->postJson('/api/v1/admin/computers', [
            'lab_id' => $lab->id, 'computer_code' => $code, 'ip_address' => '10.60.0.1', 'status' => 'exam',
        ]);

        $this->expectStatus($res, 422, 'BUG#7 store with status=exam');
        $res->assertJsonValidationErrors(['status']);
        $this->assertDatabaseMissing('computers', ['computer_code' => $code]);
    }

    public function test_21_store_accepts_locked_current(): void
    {
        $lab = Lab::factory()->create();

        $res = $this->postJson('/api/v1/admin/computers', [
            'lab_id' => $lab->id, 'computer_code' => 'NEW-LOCK-1', 'ip_address' => '10.60.0.2', 'status' => 'locked',
        ]);

        $this->expectStatus($res, 201, 'CURRENT store locked');
        $this->assertDatabaseHas('computers', ['computer_code' => 'NEW-LOCK-1', 'status' => 'locked']);
    }

    public function test_22_store_default_status_is_offline_current(): void
    {
        $lab = Lab::factory()->create();

        $res = $this->postJson('/api/v1/admin/computers', [
            'lab_id' => $lab->id, 'computer_code' => 'NEW-DEF-1', 'ip_address' => '10.60.0.3',
        ]);

        $this->expectStatus($res, 201, 'CURRENT store default');
        $this->assertDatabaseHas('computers', ['computer_code' => 'NEW-DEF-1', 'status' => 'offline']);
    }

    // ─── Computer: update ─────────────────────────────────────

    public function test_23_update_lab_of_exam_computer_is_422(): void
    {
        $lab = Lab::factory()->create();
        $other = Lab::factory()->create();
        $c = $this->makeComputer($lab, ['status' => 'exam']);
        $verb = $this->resolveUpdateMethod('api/v1/admin/computers/{computer}');

        $res = $this->sendRequest($verb, '/api/v1/admin/computers/' . $c->id, ['lab_id' => $other->id]);

        $this->expectStatus($res, 422, 'BUG#5 lab change of exam computer');
        $this->assertDatabaseHas('computers', ['id' => $c->id, 'lab_id' => $lab->id]);
    }

    public function test_24_update_lab_of_assigned_computer_is_422(): void
    {
        $lab = Lab::factory()->create();
        $other = Lab::factory()->create();
        $c = $this->makeComputer($lab, ['status' => 'online']);
        $this->makeAssignment($this->makeShift(['lab_id' => $lab->id]), $c);
        $verb = $this->resolveUpdateMethod('api/v1/admin/computers/{computer}');

        $res = $this->sendRequest($verb, '/api/v1/admin/computers/' . $c->id, ['lab_id' => $other->id]);

        $this->expectStatus($res, 422, 'BUG#5 lab change of assigned computer');
        $this->assertDatabaseHas('computers', ['id' => $c->id, 'lab_id' => $lab->id]);
    }

    public function test_25_update_lab_of_free_computer_succeeds_current(): void
    {
        $lab = Lab::factory()->create();
        $other = Lab::factory()->create();
        $c = $this->makeComputer($lab, ['status' => 'online']);
        $verb = $this->resolveUpdateMethod('api/v1/admin/computers/{computer}');

        $res = $this->sendRequest($verb, '/api/v1/admin/computers/' . $c->id, ['lab_id' => $other->id]);

        $this->expectStatus($res, 200, 'CURRENT free computer lab change');
        $this->assertDatabaseHas('computers', ['id' => $c->id, 'lab_id' => $other->id]);
    }

    public function test_26_update_same_lab_of_exam_computer_succeeds_current(): void
    {
        $lab = Lab::factory()->create();
        $c = $this->makeComputer($lab, ['status' => 'exam']);
        $verb = $this->resolveUpdateMethod('api/v1/admin/computers/{computer}');

        $res = $this->sendRequest($verb, '/api/v1/admin/computers/' . $c->id, ['lab_id' => $lab->id, 'hostname' => 'samelab-host']);

        $this->expectStatus($res, 200, 'CURRENT same lab_id of exam computer');
        $this->assertDatabaseHas('computers', ['id' => $c->id, 'hostname' => 'samelab-host']);
    }

    // ─── Computer: destroy ────────────────────────────────────

    public function test_27_destroy_assigned_computer_is_422_and_assignment_intact(): void
    {
        $lab = Lab::factory()->create();
        $c = $this->makeComputer($lab, ['status' => 'online']);
        $a = $this->makeAssignment($this->makeShift(['lab_id' => $lab->id]), $c);

        $res = $this->deleteJson('/api/v1/admin/computers/' . $c->id);

        $this->expectStatus($res, 422, 'BUG#17 delete assigned computer');
        $this->assertDatabaseHas('computers', ['id' => $c->id]);
        $this->assertDatabaseHas('candidate_assignments', ['id' => $a->id, 'computer_id' => $c->id]);
    }

    public function test_28_destroy_exam_computer_is_422_current(): void
    {
        $c = $this->makeComputer(Lab::factory()->create(), ['status' => 'exam']);

        $res = $this->deleteJson('/api/v1/admin/computers/' . $c->id);

        $this->expectStatus($res, 422, 'CURRENT delete exam computer');
        $this->assertDatabaseHas('computers', ['id' => $c->id]);
    }

    public function test_29_destroy_free_computer_succeeds_current(): void
    {
        $c = $this->makeComputer(Lab::factory()->create(), ['status' => 'offline']);

        $res = $this->deleteJson('/api/v1/admin/computers/' . $c->id);

        $this->expectStatus($res, 200, 'CURRENT delete free computer');
        $this->assertDatabaseMissing('computers', ['id' => $c->id]);
    }

    // ─── Shift ────────────────────────────────────────────────

    public function test_30_shift_update_lab_with_assignments_is_422(): void
    {
        $lab = Lab::factory()->create();
        $other = Lab::factory()->create();
        $shift = $this->makeShift(['lab_id' => $lab->id]);
        $this->makeAssignment($shift);
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['lab_id' => $other->id]);

        $this->expectStatus($res, 422, 'BUG#18 shift lab change with assignments');
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'lab_id' => $lab->id]);
    }

    public function test_31_shift_update_lab_without_assignments_succeeds_current(): void
    {
        $lab = Lab::factory()->create();
        $other = Lab::factory()->create();
        $shift = $this->makeShift(['lab_id' => $lab->id]);
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['lab_id' => $other->id]);

        $this->expectStatus($res, 200, 'CURRENT shift lab change without assignments');
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'lab_id' => $other->id]);
    }

    public function test_32_shift_capacity_below_assigned_is_422(): void
    {
        $shift = $this->makeShift(['capacity' => 5]);
        $this->makeAssignment($shift);
        $this->makeAssignment($shift);
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['capacity' => 1]);

        $this->expectStatus($res, 422, 'BUG#18 capacity below assigned');
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'capacity' => 5]);
    }

    public function test_33_shift_capacity_equal_assigned_succeeds_current(): void
    {
        $shift = $this->makeShift(['capacity' => 5]);
        $this->makeAssignment($shift);
        $this->makeAssignment($shift);
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['capacity' => 2]);

        $this->expectStatus($res, 200, 'CURRENT capacity equal to assigned');
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'capacity' => 2]);
    }

    public function test_34_shift_only_end_time_before_current_start_is_422(): void
    {
        $shift = $this->makeShift(); // start = now+1h, end = now+3h
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['end_time' => $this->ts(-2)]);

        $this->expectStatus($res, 422, 'BUG#6 only end_time before current start');
        $res->assertJsonValidationErrors(['end_time']);
    }

    public function test_35_shift_only_start_time_after_current_end_is_422(): void
    {
        $shift = $this->makeShift(); // start = now+1h, end = now+3h
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['start_time' => $this->ts(10)]);

        $this->expectStatus($res, 422, 'BUG#6 only start_time after current end');
        $res->assertJsonValidationErrors(['end_time']);
    }

    public function test_36_shift_both_times_valid_succeeds_current(): void
    {
        $shift = $this->makeShift();
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, [
            'start_time' => $this->ts(5), 'end_time' => $this->ts(7),
        ]);

        $this->expectStatus($res, 200, 'CURRENT both times valid');
    }

    public function test_37_shift_both_times_invalid_is_422_current(): void
    {
        $shift = $this->makeShift();
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, [
            'start_time' => $this->ts(8), 'end_time' => $this->ts(6),
        ]);

        $this->expectStatus($res, 422, 'CURRENT both times invalid');
    }

    public function test_38_shift_only_end_time_valid_succeeds(): void
    {
        $shift = $this->makeShift(); // start = now+1h
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['end_time' => $this->ts(6)]);

        $this->expectStatus($res, 200, 'REGRESSION GUARD (also passes on old code): only end_time valid after current start');
    }

    public function test_39_shift_name_only_update_succeeds_current(): void
    {
        $shift = $this->makeShift();
        $verb = $this->resolveUpdateMethod('api/v1/admin/shifts/{shift}');

        $res = $this->sendRequest($verb, '/api/v1/admin/shifts/' . $shift->id, ['name' => 'Renamed shift']);

        $this->expectStatus($res, 200, 'CURRENT name-only update');
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'name' => 'Renamed shift']);
    }

    public function test_40_shift_store_end_before_start_is_422_current(): void
    {
        $lab = Lab::factory()->create();
        $exam = $this->makeShift()->exam_id;

        $res = $this->postJson('/api/v1/admin/shifts', [
            'exam_id' => $exam, 'name' => 'Bad shift', 'lab_id' => $lab->id, 'capacity' => 10,
            'start_time' => $this->ts(8), 'end_time' => $this->ts(6),
        ]);

        $this->expectStatus($res, 422, 'CURRENT store end before start');
    }

    // --- Changes 10 / 11: computer deletion blocked by attempts, session transfers, incidents ---

    /**
     * Builds the parent id for a required FK column of the incidents table.
     */
    private function parentIdFor(string $table): int
    {
        return match ($table) {
            'users'      => User::factory()->create()->id,
            'candidates' => Candidate::factory()->create()->id,
            'attempts'   => Attempt::factory()->create()->id,
            'exams'      => Exam::factory()->create()->id,
            'labs'       => Lab::factory()->create()->id,
            'shifts'     => Shift::factory()->create()->id,
            default      => $this->fail("PRECONDITION: no factory mapping for FK table {$table}"),
        };
    }

    /**
     * Inserts a minimal incident row for the computer; required columns are discovered from the real schema.
     */
    private function insertIncident(Computer $computer): int
    {
        $fkMap = [];
        foreach (Schema::getForeignKeys('incidents') as $fk) {
            foreach ($fk['columns'] as $colName) {
                $fkMap[$colName] = $fk['foreign_table'];
            }
        }

        $row = ['computer_id' => $computer->id];
        $names = [];

        foreach (Schema::getColumns('incidents') as $col) {
            $name = $col['name'];
            $names[] = $name;

            if (array_key_exists($name, $row) || $col['auto_increment'] || $col['nullable'] || $col['default'] !== null) {
                continue;
            }
            if (isset($fkMap[$name])) {
                $row[$name] = $this->parentIdFor($fkMap[$name]);
                continue;
            }

            $type  = (string) $col['type'];
            $lower = strtolower($type);

            if (str_starts_with($lower, 'enum')) {
                preg_match("/'([^']*)'/", $type, $m);
                $row[$name] = $m[1] ?? '';
            } elseif (preg_match('/int|decimal|float|double/', $lower)) {
                $row[$name] = 1;
            } elseif (str_starts_with($lower, 'datetime') || str_starts_with($lower, 'timestamp')) {
                $row[$name] = now()->format('Y-m-d H:i:s');
            } elseif (str_starts_with($lower, 'date')) {
                $row[$name] = now()->format('Y-m-d');
            } elseif (str_starts_with($lower, 'json')) {
                $row[$name] = '[]';
            } else {
                $row[$name] = 'x';
            }
        }

        foreach (['created_at', 'updated_at'] as $ts) {
            if (in_array($ts, $names, true) && !array_key_exists($ts, $row)) {
                $row[$ts] = now();
            }
        }

        return (int) DB::table('incidents')->insertGetId($row);
    }

    public function test_41_destroy_computer_used_only_by_attempt_is_422(): void
    {
        $c = $this->makeComputer(Lab::factory()->create(), ['status' => 'offline']);
        $attempt = Attempt::factory()->create(['computer_id' => $c->id]);

        $res = $this->deleteJson('/api/v1/admin/computers/' . $c->id);

        $this->expectStatus($res, 422, 'CHANGE#10 delete computer used by an attempt (FK 1451)');
        $this->assertFalse((bool) $res->json('success'));
        $this->assertDatabaseHas('computers', ['id' => $c->id]);
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'computer_id' => $c->id]);
    }

    public function test_42_destroy_computer_used_only_as_transfer_target_is_422(): void
    {
        $c = $this->makeComputer(Lab::factory()->create(), ['status' => 'offline']);
        $transfer = SessionTransfer::factory()->create(['to_computer_id' => $c->id]);

        $res = $this->deleteJson('/api/v1/admin/computers/' . $c->id);

        $this->expectStatus($res, 422, 'CHANGE#10 delete computer used as transfer target (FK 1451)');
        $this->assertDatabaseHas('computers', ['id' => $c->id]);
        $this->assertDatabaseHas('session_transfers', ['id' => $transfer->id, 'to_computer_id' => $c->id]);
    }

    public function test_43_destroy_computer_used_only_as_transfer_source_is_422(): void
    {
        $c = $this->makeComputer(Lab::factory()->create(), ['status' => 'offline']);
        $transfer = SessionTransfer::factory()->create(['from_computer_id' => $c->id]);

        $res = $this->deleteJson('/api/v1/admin/computers/' . $c->id);

        $this->expectStatus($res, 422, 'CHANGE#10 delete computer used as transfer source (FK 1451)');
        $this->assertDatabaseHas('computers', ['id' => $c->id]);
        $this->assertDatabaseHas('session_transfers', ['id' => $transfer->id, 'from_computer_id' => $c->id]);
    }

    public function test_44_destroy_computer_with_incident_is_422_and_reference_kept(): void
    {
        $c = $this->makeComputer(Lab::factory()->create(), ['status' => 'offline']);
        $incidentId = $this->insertIncident($c);

        $res = $this->deleteJson('/api/v1/admin/computers/' . $c->id);

        $this->expectStatus($res, 422, 'CHANGE#11 delete computer with incident');
        $this->assertDatabaseHas('computers', ['id' => $c->id]);
        $this->assertSame(
            $c->id,
            (int) DB::table('incidents')->where('id', $incidentId)->value('computer_id'),
            'CHANGE#11 incidents.computer_id must be preserved'
        );
    }
}
