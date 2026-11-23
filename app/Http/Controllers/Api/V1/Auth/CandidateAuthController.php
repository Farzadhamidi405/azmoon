<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CandidateLoginRequest;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $profile = $this->buildCandidateProfile($candidate);

        return response()->json([
            'authenticated' => true,
            'actor_type'    => 'candidate',
            'user'          => $profile['candidate'],
            'assignment'    => $profile['assignment'],
            'entry_card'    => $profile['entry_card'],
            'readiness'     => $profile['readiness'],
        ]);
    }

    /**
     * Return detailed dashboard data for the authenticated candidate.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $candidate = Auth::guard('candidate')->user();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->buildCandidateProfile($candidate),
        ]);
    }

    /**
     * Format candidate data for basic API responses.
     */
    private function formatCandidate(Candidate $candidate): array
    {
        return [
            'id'                  => $candidate->id,
            'name'                => $candidate->name,
            'candidate_code'      => $candidate->candidate_code,
            'username'            => $candidate->username,
            'status'              => $candidate->status,
            'must_reset_password' => $candidate->must_reset_password,
        ];
    }

    /**
     * Build rich, secure candidate profile with live assignment and readiness status.
     * Never exposes password hash or raw session secrets.
     */
    private function buildCandidateProfile(Candidate $candidate): array
    {
        $assignment = $candidate->assignments()
            ->with(['exam', 'shift', 'lab', 'computer'])
            ->latest()
            ->first();

        $entryCard = $candidate->entryCards()->latest()->first();

        $exam = $assignment?->exam;
        $shift = $assignment?->shift;
        $lab = $assignment?->lab;
        $computer = $assignment?->computer;

        $readiness = $this->calculateReadiness($candidate, $assignment);

        return [
            'candidate' => [
                'id'             => $candidate->id,
                'name'           => $candidate->name,
                'candidate_code' => $candidate->candidate_code,
                'username'       => $candidate->username,
                'national_id'    => $candidate->national_id,
                'status'         => $candidate->status,
                'checked_in_at'  => $candidate->checked_in_at ? Carbon::parse($candidate->checked_in_at)->toIso8601String() : null,
                'is_checked_in'  => $candidate->checked_in_at !== null || in_array($candidate->status, ['present', 'in_progress', 'completed']),
            ],
            'assignment' => $assignment ? [
                'id'         => $assignment->id,
                'exam'       => $exam ? [
                    'id'               => $exam->id,
                    'title'            => $exam->title,
                    'duration_minutes' => (int) $exam->duration_minutes,
                    'total_marks'      => (float) $exam->total_marks,
                    'pass_marks'       => (float) $exam->pass_marks,
                    'status'           => $exam->status,
                ] : null,
                'shift'      => $shift ? [
                    'id'         => $shift->id,
                    'name'       => $shift->name,
                    'start_time' => $shift->start_time ? Carbon::parse($shift->start_time)->toIso8601String() : null,
                    'end_time'   => $shift->end_time ? Carbon::parse($shift->end_time)->toIso8601String() : null,
                ] : null,
                'lab'        => $lab ? [
                    'id'       => $lab->id,
                    'name'     => $lab->name,
                    'location' => $lab->location,
                ] : null,
                'computer'   => $computer ? [
                    'id'            => $computer->id,
                    'computer_code' => $computer->computer_code,
                ] : null,
            ] : null,
            'entry_card' => $entryCard ? [
                'has_card'      => true,
                'expires_at'    => $entryCard->qr_expires_at ? Carbon::parse($entryCard->qr_expires_at)->toIso8601String() : null,
                'is_expired'    => $entryCard->isExpired(),
            ] : [
                'has_card'      => false,
                'expires_at'    => null,
                'is_expired'    => false,
            ],
            'readiness' => $readiness,
        ];
    }

    /**
     * Calculate server-enforced readiness state for candidate.
     */
    private function calculateReadiness(Candidate $candidate, ?CandidateAssignment $assignment): array
    {
        if ($candidate->status === 'disqualified') {
            return [
                'code'      => 'disqualified',
                'label'     => 'محروم از آزمون',
                'tone'      => 'bad',
                'can_start' => false,
                'message'   => 'هویت یا پرونده شما توسط ناظر رد شده است و مجاز به شرکت در آزمون نمی‌باشید.',
            ];
        }

        if ($candidate->status === 'completed') {
            return [
                'code'      => 'completed',
                'label'     => 'آزمون تکمیل‌شده',
                'tone'      => 'teal',
                'can_start' => false,
                'message'   => 'پاسخ‌های شما قبلاً ثبت نهایی شده و این آزمون برای شما به پایان رسیده است.',
            ];
        }

        if ($candidate->status === 'in_progress') {
            return [
                'code'      => 'in_progress',
                'label'     => 'در حال برگزاری آزمون',
                'tone'      => 'gold',
                'can_start' => true,
                'message'   => 'آزمون شما فعال است. می‌توانید برای ادامه به محیط امتحان بازگردید.',
            ];
        }

        if (!$assignment || !$assignment->exam) {
            return [
                'code'      => 'pending_assignment',
                'label'     => 'در انتظار تخصیص آزمون',
                'tone'      => 'off',
                'can_start' => false,
                'message'   => 'شما هنوز به هیچ آزمون، شیفت یا صندلی‌ای تخصیص داده نشده‌اید. لطفاً با مسئول لابراتوار تماس بگیرید.',
            ];
        }

        if (!in_array($assignment->exam->status, ['published', 'running'])) {
            return [
                'code'      => 'exam_not_ready',
                'label'     => 'آزمون در وضعیت آماده‌سازی',
                'tone'      => 'pending',
                'can_start' => false,
                'message'   => 'آزمون شما هنوز توسط هیئت علمی منتشر نشده است.',
            ];
        }

        if ($candidate->checked_in_at === null && $candidate->status !== 'present') {
            return [
                'code'      => 'pending_checkin',
                'label'     => 'در انتظار Check-in و تأیید هویت',
                'tone'      => 'pending',
                'can_start' => false,
                'message'   => 'حضور شما در جلسه آزمون هنوز ثبت نشده است. لطفاً جهت احراز هویت به مسئول باجه ورودی مراجعه فرمایید.',
            ];
        }

        if (!$assignment->computer_id) {
            return [
                'code'      => 'pending_computer',
                'label'     => 'در انتظار تعیین کامپیوتر',
                'tone'      => 'pending',
                'can_start' => false,
                'message'   => 'شماره صندلی/کامپیوتر شما هنوز تعیین نشده است. لطفاً به مسئول لابراتوار مراجعه کنید.',
            ];
        }

        return [
            'code'      => 'ready',
            'label'     => 'آماده شروع آزمون',
            'tone'      => 'teal',
            'can_start' => true,
            'message'   => 'هویت، حضور و صندلی شما با موفقیت تأیید شده است. می‌توانید وارد دستورالعمل شده و آزمون را آغاز کنید.',
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