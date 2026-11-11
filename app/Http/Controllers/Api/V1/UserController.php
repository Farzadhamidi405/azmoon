<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Arr; // audit: strip password fields

/**
 * UserController — مدیریت کاربران Staff سیستم.
 * فقط Super Admin دسترسی دارد.
 * بند 3 SRS — نقش‌های سیستم.
 */
class UserController extends Controller
{
    // ─── Index ────────────────────────────────────────────────

    /**
     * لیست تمام کاربران Staff با نقش آن‌ها.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = User::with('role:id,name')
            ->orderBy('name')
            ->get()
            ->makeHidden(['two_factor_secret']);

        return response()->json([
            'success' => true,
            'data'    => $users,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * ایجاد کاربر Staff جدید.
     * در Audit Log ثبت می‌شود.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            // ایجاد کاربر جدید
            $user = User::create([
                'name'      => $request->name,
                'username'  => $request->username,
                'email'     => $request->email,
                'password'  => $request->password,
                'role_id'   => $request->role_id,
                'is_active' => $request->boolean('is_active', true),
            ]);

            // ثبت در audit_logs — بند 33 SRS
            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'User:created',
                'module'      => 'users',
                'target_type' => 'User',
                'target_id'   => $user->id,
                'new_value'   => json_encode([
                    'name'     => $user->name,
                    'username' => $user->username,
                    'role_id'  => $user->role_id,
                ]),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);

            return $user;
        });

        $user->load('role:id,name');

        return response()->json([
            'success' => true,
            'message' => 'کاربر با موفقیت ایجاد شد.',
            'data'    => $user->makeHidden(['two_factor_secret']),
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * مشاهده جزئیات یک کاربر.
     */
    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        $user->load('role:id,name');

        return response()->json([
            'success' => true,
            'data'    => $user->makeHidden(['two_factor_secret']),
        ]);
    }

    // ─── Update ───────────────────────────────────────────────

    /**
     * ویرایش اطلاعات کاربر.
     * در Audit Log ثبت می‌شود.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $oldValues = [
            'name'      => $user->name,
            'username'  => $user->username,
            'role_id'   => $user->role_id,
            'is_active' => $user->is_active,
        ];

        DB::transaction(function () use ($request, $user, $oldValues) {
            // فقط فیلدهای ارسال‌شده را به‌روز کن
            $data = $request->only(['name', 'username', 'email', 'role_id', 'is_active']);

            if ($request->filled('password')) {
                $data['password'] = $request->password;
            }

            $user->update($data);

            // ثبت در audit_logs — بند 33 SRS
            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'User:updated',
                'module'      => 'users',
                'target_type' => 'User',
                'target_id'   => $user->id,
                'old_value'   => json_encode($oldValues),
                // SECURITY: never store the raw password in audit_logs.
                // Remove password/password_confirmation; when a password was changed record only a boolean flag.
                'new_value' => json_encode(Arr::except($data, ['password', 'password_confirmation'])
                    + (array_key_exists('password', $data) ? ['password_changed' => true] : [])),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);
        });

        $user->load('role:id,name');

        return response()->json([
            'success' => true,
            'message' => 'کاربر با موفقیت به‌روز شد.',
            'data'    => $user->makeHidden(['two_factor_secret']),
        ]);
    }

    // ─── Destroy ──────────────────────────────────────────────

    /**
     * غیرفعال‌کردن کاربر (Soft Deactivate).
     * Super Admin نمی‌تواند خودش را غیرفعال کند.
     * در Audit Log ثبت می‌شود.
     */
    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => false]);

            // ثبت در audit_logs — بند 33 SRS
            DB::table('audit_logs')->insert([
                'user_id'     => request()->user()->id,
                'role'        => request()->user()->role?->name,
                'action'      => 'User:deactivated',
                'module'      => 'users',
                'target_type' => 'User',
                'target_id'   => $user->id,
                'old_value'   => json_encode(['is_active' => true]),
                'new_value'   => json_encode(['is_active' => false]),
                'ip_address'  => request()->ip(),
                'user_agent'  => request()->userAgent(),
                'created_at'  => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'کاربر با موفقیت غیرفعال شد.',
        ]);
    }
}