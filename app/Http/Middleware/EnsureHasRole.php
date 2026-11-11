<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasRole
{
    /**
     * Ensure the logged-in staff user has one of the required roles.
     *
     * Why admin guard only: candidate accounts have no roles or permissions,
     * so they must never pass this middleware — they use auth:candidate routes.
     *
     * Why Super Admin bypass: SRS says Super Admin has full access without
     * checking the permissions table, so role lists do not restrict them.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
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

        // If no role names were passed, any authenticated admin may pass.
        if (empty($roles)) {
            return $next($request);
        }

        // Load role once if not already loaded — avoids extra queries later.
        $user->loadMissing('role');

        $roleName = $user->role?->name;

        // Super Admin bypasses all role lists — full access by definition.
        if ($roleName === 'Super Admin') {
            return $next($request);
        }

        // User has no role or role is not in the allowed list.
        if ($roleName === null || !in_array($roleName, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'دسترسی غیرمجاز است.',
            ], 403);
        }

        return $next($request);
    }
}