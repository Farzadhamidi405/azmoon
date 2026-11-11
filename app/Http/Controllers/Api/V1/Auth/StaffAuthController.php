<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StaffAuthController extends Controller
{
    /**
     * Handle staff/admin login.
     *
     * Accepts username or email as the login field.
     * Checks account lock status before verifying credentials.
     * On success: starts session via admin guard.
     * On failure: increments failed attempt counter and locks if threshold reached.
     */
   public function login(StaffLoginRequest $request): JsonResponse
{
    $login    = $request->input('login');
    $password = $request->input('password');

    // Find user by username or email.
    $user = User::where('username', $login)
        ->orWhere('email', $login)
        ->first();

    // Return generic error if user not found.
    if (!$user) {
        throw ValidationException::withMessages([
            'login' => ['اطلاعات ورود نادرست است.'],
        ]);
    }

    // Check if account is inactive.
    if (!$user->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'حساب کاربری شما غیرفعال شده است.',
        ], 403);
    }

    // Check if account is temporarily locked.
    if ($user->isLocked()) {
        return response()->json([
            'success' => false,
            'message' => 'حساب کاربری به دلیل تلاش‌های ناموفق قفل شده است.',
        ], 429);
    }

    // Verify password.
    if (!Hash::check($password, $user->password)) {
        $this->handleFailedAttempt($user);

        throw ValidationException::withMessages([
            'login' => ['اطلاعات ورود نادرست است.'],
        ]);
    }

    // Credentials verified — reset failed attempts and record login time.
    $user->update([
        'failed_login_attempts' => 0,
        'locked_until'          => null,
        'last_login_at'         => now(),
    ]);

    // Log in via the session (web) guard. auth:admin resolves this session through Sanctum (sanctum.guard = web).
    Auth::guard('web')->login($user);

    // Regenerate session only if session driver is available.
    // When called from SPA (React), session middleware is active via statefulApi().
    // When called from curl/Postman without cookies, session is not available.
    if ($request->hasSession()) {
        $request->session()->regenerate();
    }

    return response()->json([
        'success'    => true,
        'actor_type' => 'admin',
        'message'    => 'ورود موفقیت‌آمیز بود.',
        'user'       => $this->formatUser($user),
    ]);
}

    /**
     * Log out the currently authenticated staff user.
     * Invalidates the session and clears the session cookie.
     */
   public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'از سیستم خارج شدید.',
        ]);
    }

    /**
     * Return the currently authenticated staff user's profile.
     * Includes role name and loaded permissions for frontend route guards.
     */
    public function me(Request $request): JsonResponse
    {
        $user = Auth::guard('admin')->user();

        if (!$user) {
            return response()->json([
                'authenticated' => false,
                'message'       => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'authenticated' => true,
            'actor_type'    => 'admin',
            'user'          => $this->formatUser($user),
        ]);
    }

    /**
     * Format user data for API response.
     * Loads role and permissions without exposing sensitive fields.
     */
    private function formatUser(User $user): array
    {
        // Eager load role and its permissions once for this response.
        $user->loadMissing('role.permissions');

        $permissions = [];

        if ($user->role) {
            // Super Admin gets a wildcard marker — actual checks use hasPermission().
            if ($user->role->name === 'Super Admin') {
                $permissions = ['*'];
            } else {
                $permissions = $user->role->permissions
                    ->map(fn($p) => $p->module . '.' . $p->action)
                    ->values()
                    ->all();
            }
        }

        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'username'    => $user->username,
            'email'       => $user->email,
            'role'        => $user->role?->name,
            'permissions' => $permissions,
        ];
    }

    /**
     * Increment failed login attempts and lock account if threshold is reached.
     * Threshold: 5 attempts → lock for 15 minutes.
     * This mirrors the security policy in SRS section 35.
     */
    private function handleFailedAttempt(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $lockedUntil = null;

        // Lock the account after 5 consecutive failed attempts.
        if ($attempts >= 5) {
            $lockedUntil = now()->addMinutes(15);
        }

        $user->update([
            'failed_login_attempts' => $attempts,
            'locked_until'          => $lockedUntil,
        ]);
    }
}