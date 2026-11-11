<?php

namespace Tests\Feature\Api\V1;

use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Lab;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Contract test for the Auto-Assign API as used by the Shifts page modal.
 * Checks the response shape (keys read by AutoAssignModal), real candidate/shift names,
 * the confirm result and the authorization rule (labs.update).
 */
class AutoAssignContractTest extends TestCase
{
    use RefreshDatabase;

    /** Security guard: these tests may only run against the test database. */
    protected function setUp(): void
    {
        parent::setUp();

        $dbName = (string) DB::connection()->getDatabaseName();
        if ($dbName !== 'azmoon_test') {
            $this->fail("Security Guard: tests must ONLY run against 'azmoon_test'. Current database: '{$dbName}'.");
        }
    }

    /** Creates a user that has the given seeded role. */
    private function makeUser(string $roleName): User
    {
        $this->seed(RoleSeeder::class);
        $role = Role::where('name', $roleName)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /** Builds one shift with one online computer and one unassigned candidate. */
    private function makeFixture(): array
    {
        $lab = Lab::factory()->create();
        $shift = Shift::factory()->create(['lab_id' => $lab->id, 'name' => 'Contract Shift', 'capacity' => 10]);
        $computer = Computer::factory()->create([
            'lab_id'     => $lab->id,
            'status'     => 'online',
            'ip_address' => '10.0.0.5',
        ]);
        $candidate = Candidate::factory()->create([
            'name'        => 'Contract Candidate',
            'national_id' => '1234567890',
            'status'      => 'present',
        ]);
        $assignment = CandidateAssignment::factory()->create([
            'candidate_id' => $candidate->id,
            'exam_id'      => $shift->exam_id,
            'shift_id'     => $shift->id,
            'lab_id'       => $lab->id,
            'computer_id'  => null,
        ]);

        return [$shift, $computer, $candidate, $assignment];
    }

    /** Auto-assign endpoint URL for a shift. */
    private function url(Shift $shift): string
    {
        return "/api/v1/admin/labs/shifts/{$shift->id}/auto-assign";
    }

    /** Preview returns every key the modal reads, with real names (no nulls) and saves nothing. */
    public function test_01_preview_returns_ui_contract_with_real_names(): void
    {
        Gate::before(fn () => true);
        $admin = $this->makeUser('Super Admin');
        [$shift, $computer, $candidate, $assignment] = $this->makeFixture();

        $response = $this->actingAs($admin, 'admin')->postJson($this->url($shift), ['confirmed' => false]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', false)
            ->assertJsonPath('mode', 'preview')
            ->assertJsonStructure([
                'data' => [
                    'shift'       => ['id', 'title', 'capacity', 'lab_name'],
                    'summary'     => ['total_unassigned', 'available_computers', 'remaining_capacity', 'will_be_assigned', 'will_remain'],
                    'assignments' => ['*' => ['assignment_id', 'candidate_id', 'candidate_name', 'national_id', 'computer_id', 'computer_code', 'ip_address']],
                    'unassigned',
                ],
            ])
            ->assertJsonPath('data.shift.title', $shift->name)
            ->assertJsonPath('data.summary.will_be_assigned', 1)
            ->assertJsonPath('data.assignments.0.candidate_name', $candidate->name)
            ->assertJsonPath('data.assignments.0.national_id', $candidate->national_id)
            ->assertJsonPath('data.assignments.0.computer_code', $computer->computer_code)
            ->assertJsonPath('data.assignments.0.ip_address', '10.0.0.5');

        $this->assertDatabaseHas('candidate_assignments', ['id' => $assignment->id, 'computer_id' => null]);
    }

    /** Confirm saves the assignment, returns the count and message, and writes an audit row. */
    public function test_02_confirm_saves_assignment_and_returns_result(): void
    {
        Gate::before(fn () => true);
        $admin = $this->makeUser('Super Admin');
        [$shift, $computer, $candidate, $assignment] = $this->makeFixture();

        $response = $this->actingAs($admin, 'admin')->postJson($this->url($shift), ['confirmed' => true]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('mode', 'confirmed')
            ->assertJsonPath('assigned', 1);
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));

        $this->assertDatabaseHas('candidate_assignments', [
            'id'          => $assignment->id,
            'computer_id' => $computer->id,
            'assigned_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'shift.auto_assigned',
            'module'    => 'labs',
            'target_id' => $shift->id,
        ]);
    }

    /** A user without labs.update gets 403 and nothing is saved. */
    public function test_03_user_without_permission_gets_403(): void
    {
        $user = $this->makeUser('Reviewer');
        [$shift, $computer, $candidate, $assignment] = $this->makeFixture();

        $this->actingAs($user, 'admin')
            ->postJson($this->url($shift), ['confirmed' => false])
            ->assertStatus(403);

        $this->assertDatabaseHas('candidate_assignments', ['id' => $assignment->id, 'computer_id' => null]);
    }
}
