<?php

namespace App\Http\Middleware;

use App\Models\UserSubjectAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubjectAccess
{
    /**
     * Ensure staff user has access to the requested subject.
     *
     * Why admin guard only: candidate accounts have no subject access,
     * so they must never pass this middleware — they use auth:candidate routes.
     *
     * Why Super Admin + Exam Administrator bypass: SRS 60.6 says they are
     * exempt from subject restriction and have full access to all subjects.
     *
     * How subject is resolved: explicit middleware param first, then route
     * param {subject} / {subject_id}, then request input subject_id.
     * If no subject is specified (list endpoints), request passes through —
     * controller must filter by allowed subjects with WHERE subject_id IN (...).
     */
    public function handle(Request $request, Closure $next, $subjectId = null): Response
    {
        // Get user from admin guard only — keeps staff and candidate auth separate.
        $user = Auth::guard('admin')->user();
        /** @var \App\Models\User $user */

        // No staff user logged in — client must login first.
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'احراز هویت نشده است.',
            ], 401);
        }

        // Inactive staff cannot use the system even with a valid session.
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال شده است.',
            ], 403);
        }

        // Locked accounts must wait — prevents brute-force continuation.
        if (method_exists($user, 'isLocked') && $user->isLocked()) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری به دلیل تلاش‌های ناموفق قفل شده است.',
            ], 429);
        }

        // Load role once if not already loaded — avoids extra queries later.
        $user->loadMissing('role');

        $roleName = $user->role?->name;

        // Super Admin and Exam Administrator have full access to all subjects.
        if ($roleName === 'Super Admin' || $roleName === 'Exam Administrator') {
            return $next($request);
        }

        // Resolve subject ID from middleware param, route param, or request input.
        $resolved = $subjectId
            ?? $request->route('subject')
            ?? $request->route('subject_id')
            ?? $request->input('subject_id');

        // Route model binding may give a Subject model instead of raw ID.
        if (is_object($resolved) && isset($resolved->id)) {
            $resolved = $resolved->id;
        }

        // No specific subject requested — let controller filter by allowed subjects.
        if ($resolved === null || $resolved === '') {
            return $next($request);
        }

        // Check user_subject_access table for this user + subject pair.
        $hasAccess = UserSubjectAccess::where('user_id', $user->id)
            ->where('subject_id', $resolved)
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'دسترسی به این مضمون ندارید.',
            ], 403);
        }

        return $next($request);
    }
}