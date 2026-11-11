<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManualOverride\StoreManualOverrideRequest;
use App\Models\Attempt;
use App\Models\ManualOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ManualOverrideController — عملیات دستی ادمین روی Attempt.
 * شامل: ثبت Override و مشاهده تاریخچه.
 * Override ها immutable هستند — update/delete مجاز نیست.
 * هر Override در audit_logs ثبت می‌شود.
 * بند 27 SRS — Section 12.
 */
class ManualOverrideController extends Controller
{
    // ─── Index ────────────────────────────────────────────────

    /**
     * لیست Override های یک Attempt.
     * تاریخچه کامل عملیات دستی روی این Attempt.
     */
    public function index(Attempt $attempt): JsonResponse
    {
        Gate::authorize('viewAny', ManualOverride::class);

        $overrides = $attempt->manualOverrides()
            ->with(['performedBy:id,name,username'])
            ->orderByDesc('performed_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $overrides,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * ثبت Manual Override جدید روی یک Attempt.
     * هر Override نیازمند دلیل است — بدون دلیل ثبت نمی‌شود.
     * عملیات در یک Transaction انجام می‌شود:
     *   1. ثبت ManualOverride
     *   2. ثبت در audit_logs
     */
    public function store(StoreManualOverrideRequest $request, Attempt $attempt): JsonResponse
    {
        $override = DB::transaction(function () use ($request, $attempt) {
            // ثبت Override با اطلاعات کاربر و زمان
            $override = ManualOverride::create([
                'attempt_id'   => $attempt->id,
                'action'       => $request->action,
                'reason'       => $request->reason,
                'old_value'    => $request->old_value,
                'new_value'    => $request->new_value,
                'performed_by' => $request->user()->id,
                'performed_at' => now(),
            ]);

            // ثبت در audit_logs — بند 27 و 33 SRS
            // هر Override باید با User، زمان، و دلیل در Audit ثبت شود
            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'ManualOverride:' . $request->action,
                'module'      => 'attempts',
                'target_type' => 'Attempt',
                'target_id'   => $attempt->id,
                'old_value'   => $request->old_value
                                    ? json_encode($request->old_value)
                                    : null,
                'new_value'   => $request->new_value
                                    ? json_encode($request->new_value)
                                    : null,
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);

            return $override;
        });

        $override->load(['performedBy:id,name,username']);

        return response()->json([
            'success' => true,
            'message' => 'Override با موفقیت ثبت شد.',
            'data'    => $override,
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * جزئیات یک Override خاص.
     */
    public function show(Attempt $attempt, ManualOverride $manualOverride): JsonResponse
    {
        Gate::authorize('view', $manualOverride);

        // بررسی تعلق Override به همین Attempt
        if ($manualOverride->attempt_id !== $attempt->id) {
            return response()->json([
                'success' => false,
                'message' => 'این Override متعلق به این Attempt نیست.',
            ], 404);
        }

        $manualOverride->load(['performedBy:id,name,username']);

        return response()->json([
            'success' => true,
            'data'    => $manualOverride,
        ]);
    }
}