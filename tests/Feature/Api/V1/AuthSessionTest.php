<?php

namespace Tests\Feature\Api\V1;

use App\Models\Candidate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real cookie/session login for staff and candidates.
 *
 * These tests go through the login and logout endpoints instead of actingAs(),
 * so the guard drivers are exercised for real. Passwords are generated inside
 * each test; no real credentials are used.
 */
class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:8000';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:8000']]);

        $this->withCredentials();
    }

    private function resetRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function send(string $method, string $uri, array $data = [], ?string $sid = null)
    {
        $this->resetRequestState();

        if ($sid !== null) {
            $this->defaultCookies[config('session.cookie')] = $sid;
        }

        return $this->json($method, $uri, $data, [
            'Origin' => self::ORIGIN,
            'Accept' => 'application/json',
        ]);
    }

    private function sendWithBearer(string $uri, string $token)
    {
        $this->resetRequestState();

        return $this->json('GET', $uri, [], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    private function currentSessionId(): string
    {
        return $this->app['session']->driver()->getId();
    }

    private function makeStaff(array $overrides = []): array
    {
        $password = 'Pw-'.Str::random(16);
        $role = Role::factory()->create(['name' => 'Super Admin']);
        $user = User::factory()->create(array_merge([
            'username' => 'staff_'.Str::lower(Str::random(8)),
            'email' => Str::lower(Str::random(8)).'@example.test',
            'role_id' => $role->id,
            'is_active' => true,
            'password' => $password,
        ], $overrides));

        return [$user, $password];
    }

    private function makeCandidate(array $overrides = []): array
    {
        $password = 'Pw-'.Str::random(16);
        $candidate = Candidate::factory()->create(array_merge([
            'candidate_code' => 'AS'.Str::upper(Str::random(6)),
            'username' => 'cand_'.Str::lower(Str::random(8)),
            'password' => $password,
        ], $overrides));

        return [$candidate, $password];
    }

    private function loginStaff(User $user, string $password, ?string $sid = null)
    {
        return $this->send('POST', '/api/v1/auth/login', [
            'login' => $user->username,
            'password' => $password,
        ], $sid);
    }

    private function loginCandidate(Candidate $candidate, string $password, ?string $sid = null)
    {
        return $this->send('POST', '/api/v1/auth/candidate/login', [
            'login' => $candidate->username,
            'password' => $password,
        ], $sid);
    }

    // ---------------------------------------------------------------- staff

    #[Test]
    public function staff_login_succeeds_and_me_returns_the_same_user(): void
    {
        [$user, $password] = $this->makeStaff();

        $this->loginStaff($user, $password)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'actor_type' => 'admin',
                'user' => ['id' => $user->id, 'username' => $user->username],
            ])
            ->assertJsonMissingPath('user.password');

        $sid = $this->currentSessionId();

        $this->send('GET', '/api/v1/auth/me', [], $sid)
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'actor_type' => 'admin',
                'user' => ['id' => $user->id, 'username' => $user->username],
            ]);
    }

    #[Test]
    public function successful_staff_login_resets_failed_attempts_and_records_login_time(): void
    {
        [$user, $password] = $this->makeStaff(['failed_login_attempts' => 2]);

        $this->loginStaff($user, $password)->assertOk();

        $user->refresh();
        $this->assertSame(0, (int) $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNotNull($user->last_login_at);
    }

    #[Test]
    public function staff_login_with_wrong_password_returns_422_and_counts_the_failure(): void
    {
        [$user] = $this->makeStaff();

        $this->loginStaff($user, 'wrong-'.Str::random(8))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login']);

        $this->assertSame(1, (int) $user->refresh()->failed_login_attempts);
    }

    #[Test]
    public function staff_login_with_unknown_account_returns_422(): void
    {
        $this->send('POST', '/api/v1/auth/login', [
            'login' => 'nobody_'.Str::lower(Str::random(8)),
            'password' => 'wrong-'.Str::random(8),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    #[Test]
    public function inactive_staff_account_cannot_log_in(): void
    {
        [$user, $password] = $this->makeStaff(['is_active' => false]);

        $this->loginStaff($user, $password)->assertStatus(403);
    }

    #[Test]
    public function locked_staff_account_cannot_log_in(): void
    {
        [$user, $password] = $this->makeStaff(['locked_until' => now()->addMinutes(10)]);

        $this->loginStaff($user, $password)->assertStatus(429);
    }

    #[Test]
    public function staff_me_without_a_session_returns_401(): void
    {
        $this->send('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    #[Test]
    public function staff_logout_ends_the_session(): void
    {
        [$user, $password] = $this->makeStaff();

        $this->loginStaff($user, $password)->assertOk();
        $sid = $this->currentSessionId();

        $this->send('GET', '/api/v1/auth/me', [], $sid)->assertOk();

        $this->send('POST', '/api/v1/auth/logout', [], $sid)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->send('GET', '/api/v1/auth/me', [], $sid)->assertStatus(401);
    }

    // ------------------------------------------------------------ candidate

    #[Test]
    public function candidate_login_succeeds_and_me_returns_the_same_candidate(): void
    {
        [$candidate, $password] = $this->makeCandidate();

        $this->loginCandidate($candidate, $password)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'actor_type' => 'candidate',
                'user' => ['id' => $candidate->id, 'username' => $candidate->username],
            ])
            ->assertJsonMissingPath('user.password');

        $sid = $this->currentSessionId();

        $this->send('GET', '/api/v1/auth/candidate/me', [], $sid)
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'actor_type' => 'candidate',
                'user' => ['id' => $candidate->id, 'username' => $candidate->username],
            ]);
    }

    #[Test]
    public function candidate_login_with_wrong_password_returns_422_and_counts_the_failure(): void
    {
        [$candidate] = $this->makeCandidate();

        $this->loginCandidate($candidate, 'wrong-'.Str::random(8))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login']);

        $this->assertSame(1, (int) $candidate->refresh()->failed_login_attempts);
    }

    #[Test]
    public function locked_candidate_cannot_log_in(): void
    {
        [$candidate, $password] = $this->makeCandidate(['locked_until' => now()->addDay()]);

        $this->loginCandidate($candidate, $password)->assertStatus(429);
    }

    #[Test]
    public function candidate_with_expired_credentials_cannot_log_in(): void
    {
        [$candidate, $password] = $this->makeCandidate(['credential_expires_at' => now()->subDay()]);

        $this->loginCandidate($candidate, $password)->assertStatus(403);
    }

    #[Test]
    public function candidate_me_without_a_session_returns_401(): void
    {
        $this->send('GET', '/api/v1/auth/candidate/me')->assertStatus(401);
    }

    #[Test]
    public function candidate_logout_ends_the_session(): void
    {
        [$candidate, $password] = $this->makeCandidate();

        $this->loginCandidate($candidate, $password)->assertOk();
        $sid = $this->currentSessionId();

        $this->send('GET', '/api/v1/auth/candidate/me', [], $sid)->assertOk();

        $this->send('POST', '/api/v1/auth/candidate/logout', [], $sid)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->send('GET', '/api/v1/auth/candidate/me', [], $sid)->assertStatus(401);
    }

    // ------------------------------------------------------------ isolation

    #[Test]
    public function staff_session_does_not_authenticate_candidate_routes(): void
    {
        [$user, $password] = $this->makeStaff();

        $this->loginStaff($user, $password)->assertOk();
        $sid = $this->currentSessionId();

        $this->send('GET', '/api/v1/auth/candidate/me', [], $sid)->assertStatus(401);
    }

    #[Test]
    public function candidate_session_does_not_authenticate_staff_routes(): void
    {
        [$candidate, $password] = $this->makeCandidate();

        $this->loginCandidate($candidate, $password)->assertOk();
        $sid = $this->currentSessionId();

        $this->send('GET', '/api/v1/auth/me', [], $sid)->assertStatus(401);
        $this->send('GET', '/api/v1/admin/users', [], $sid)->assertStatus(401);
    }

    #[Test]
    public function staff_and_candidate_sessions_coexist_with_separate_identities(): void
    {
        [$user, $staffPassword] = $this->makeStaff();
        [$candidate, $candidatePassword] = $this->makeCandidate();

        $this->loginStaff($user, $staffPassword)->assertOk();
        $sid1 = $this->currentSessionId();

        $this->loginCandidate($candidate, $candidatePassword, $sid1)->assertOk();
        $sid2 = $this->currentSessionId();

        $this->send('GET', '/api/v1/auth/me', [], $sid2)
            ->assertOk()
            ->assertJson([
                'actor_type' => 'admin',
                'user' => ['id' => $user->id, 'username' => $user->username],
            ]);

        $this->send('GET', '/api/v1/auth/candidate/me', [], $sid2)
            ->assertOk()
            ->assertJson([
                'actor_type' => 'candidate',
                'user' => ['id' => $candidate->id, 'username' => $candidate->username],
            ]);
    }

    // -------------------------------------------------------- bearer regress

    #[Test]
    public function bearer_token_still_authenticates_staff_routes(): void
    {
        [$user] = $this->makeStaff();
        $token = $user->createToken('test')->plainTextToken;

        $this->sendWithBearer('/api/v1/auth/me', $token)
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user' => ['id' => $user->id],
            ]);
    }

    #[Test]
    public function staff_bearer_token_does_not_authenticate_candidate_routes(): void
    {
        [$user] = $this->makeStaff();
        $token = $user->createToken('test')->plainTextToken;

        $this->sendWithBearer('/api/v1/auth/candidate/me', $token)->assertStatus(401);
    }
}