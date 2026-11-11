<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectAccess\StoreSubjectAccessRequest;
use App\Models\Subject;
use App\Models\UserSubjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SubjectAccessController — تخصیص/لغو دسترسی مضمون به کاربران.
 * بند 60.6 SRS — دسترسی مضمون‌محور برای Question Manager/Reviewer.
 */
class SubjectAccessController extends Controller
{
    // ─── Index ────────────────────────────────────────────────

    /**
     * لیست کاربرانی که به این مضمون دسترسی دارند.
     */
    public function index(Subject $subject): JsonResponse
    {
        Gate::authorize('view', $subject);

        $access = $subject->userAccess()
            ->with(['user:id,name,username', 'grantedBy:id,name,username'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $access,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * تخصیص دسترسی مضمون به یک کاربر.
     * در Audit Log ثبت می‌شود.
     */
    public function store(StoreSubjectAccessRequest $request, Subject $subject): JsonResponse
    {
        $access = DB::transaction(function () use ($request, $subject) {
            $access = UserSubjectAccess::create([
                'user_id'    => $request->user_id,
                'subject_id' => $subject->id,
                'granted_by' => $request->user()->id,
                'granted_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'SubjectAccess:granted',
                'module'      => 'subjects',
                'target_type' => 'UserSubjectAccess',
                'target_id'   => $access->id,
                'new_value'   => json_encode([
                    'user_id'    => $access->user_id,
                    'subject_id' => $access->subject_id,
                ]),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);

            return $access;
        });

        $access->load(['user:id,name,username', 'grantedBy:id,name,username']);

        return response()->json([
            'success' => true,
            'message' => 'دسترسی مضمون با موفقیت تخصیص یافت.',
            'data'    => $access,
        ], 201);
    }

    // ─── Destroy ──────────────────────────────────────────────

    /**
     * لغو دسترسی مضمون از یک کاربر.
     * در Audit Log ثبت می‌شود.
     */
    public function destroy(Subject $subject, UserSubjectAccess $userSubjectAccess): JsonResponse
    {
        Gate::authorize('assignAccess', $subject);

        if ($userSubjectAccess->subject_id !== $subject->id) {
            return response()->json([
                'success' => false,
                'message' => 'این رکورد دسترسی متعلق به این مضمون نیست.',
            ], 404);
        }

        DB::transaction(function () use ($userSubjectAccess) {
            DB::table('audit_logs')->insert([
                'user_id'     => request()->user()->id,
                'role'        => request()->user()->role?->name,
                'action'      => 'SubjectAccess:revoked',
                'module'      => 'subjects',
                'target_type' => 'UserSubjectAccess',
                'target_id'   => $userSubjectAccess->id,
                'old_value'   => json_encode([
                    'user_id'    => $userSubjectAccess->user_id,
                    'subject_id' => $userSubjectAccess->subject_id,
                ]),
                'ip_address'  => request()->ip(),
                'user_agent'  => request()->userAgent(),
                'created_at'  => now(),
            ]);

            $userSubjectAccess->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'دسترسی مضمون با موفقیت لغو شد.',
        ]);
    }
}