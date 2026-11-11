<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasPermission
{
    /**
     * Ensure the logged-in staff user has all required permissions.
     *
     * Permission format: "module.action" — example: "questions.create"
     * Why admin guard only: candidate accounts have no roles or permissions,
     * so they must never pass this middleware — they use auth:candidate routes.
     *
     * Why Super Admin bypass: User::hasPermission() already returns true
     * for Super Admin without checking the permissions table.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
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

        // If no permission names were passed, any authenticated admin may pass.
        if (empty($permissions)) {
            return $next($request);
        }

        // Check every required permission — user must have ALL of them.
        foreach ($permissions as $permission) {
            if (!method_exists($user, 'hasPermission') || !$user->hasPermission($permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'دسترسی غیرمجاز است.',
                ], 403);
            }
        }

        return $next($request);
    }
}