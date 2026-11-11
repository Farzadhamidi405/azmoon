<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CandidateLoginRequest;
use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CandidateAuthController extends Controller
{
    /**
     * Handle candidate login.
     *
     * Accepts username or candidate_code as the login field.
     * Checks account lock and credential expiry before verifying password.
     * Candidate authentication is completely separate from staff/admin auth.
     */
    public function login(CandidateLoginRequest $request): JsonResponse
    {
        $login    = $request->input('login');
        $password = $request->input('password');

        // Find candidate by username or candidate_code.
        $candidate = Candidate::where('username', $login)
            ->orWhere('candidate_code', $login)
            ->first();

        // Generic error — do not reveal whether the account exists.
        if (!$candidate) {
            throw ValidationException::withMessages([
                'login' => ['اطلاعات ورود نادرست است.'],
            ]);
        }

        // Check if account is locked due to failed attempts.
        if ($candidate->isLocked()) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری قفل شده است. لطفاً با مسئول آزمایشگاه تماس بگیرید.',
            ], 429);
        }

        // Check if temporary entry-card credentials have expired.
        if ($candidate->isCredentialExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'اعتبار کارت ورود منقضی شده است. لطفاً با مسئول آزمایشگاه تماس بگیرید.',
            ], 403);
        }

        // Verify password.
        if (!Hash::check($password, $candidate->password)) {
            $this->handleFailedAttempt($candidate);

            throw ValidationException::withMessages([
                'login' => ['اطلاعات ورود نادرست است.'],
            ]);
        }

        // Credentials verified — reset failed attempts and record login time.
        $candidate->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
        ]);

        // Log in via candidate guard.
        Auth::guard('candidate')->login($candidate);

        // Regenerate session only when session middleware is active (SPA context).
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'success'    => true,
            'actor_type' => 'candidate',
            'message'    => 'ورود موفقیت‌آمیز بود.',
            'user'       => $this->formatCandidate($candidate),
        ]);
    }

    /**
     * Log out the currently authenticated candidate.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('candidate')->logout();

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
     * Return the currently authenticated candidate's profile.
     * Does not include role or permissions — candidates have neither.
     */
    public function me(Request $request): JsonResponse
    {
        $candidate = Auth::guard('candidate')->user();

        if (!$candidate) {
            return response()->json([
                'authenticated' => false,
                'message'       => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'authenticated' => true,
            'actor_type'    => 'candidate',
            'user'          => $this->formatCandidate($candidate),
        ]);
    }

    /**
     * Format candidate data for API response.
     * Sensitive fields are excluded — password is hidden by model.
     */
    private function formatCandidate(Candidate $candidate): array
    {
        return [
            'id'             => $candidate->id,
            'name'           => $candidate->name,
            'candidate_code' => $candidate->candidate_code,
            'username'       => $candidate->username,
            'status'         => $candidate->status,
            'must_reset_password' => $candidate->must_reset_password,
        ];
    }

    /**
     * Increment failed login attempts and lock account if threshold reached.
     * Threshold: 5 attempts → permanent lock until admin resets.
     * Candidates cannot unlock themselves — must contact Lab Manager.
     */
    private function handleFailedAttempt(Candidate $candidate): void
    {
        $attempts = $candidate->failed_login_attempts + 1;

        // Lock candidate account after 5 failed attempts.
        // Unlike staff, candidate lock has no auto-expiry — admin must reset.
        $lockedUntil = $attempts >= 5
            ? now()->addYears(10)
            : null;

        $candidate->update([
            'failed_login_attempts' => $attempts,
            'locked_until'          => $lockedUntil,
        ]);
    }
}