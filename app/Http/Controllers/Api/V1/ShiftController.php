<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ShiftController — مدیریت شفت‌های امتحان.
 * هر شفت به یک آزمون و یک لابراتوار وصل است.
 * بند 25 SRS — Section 7.
 */
class ShiftController extends Controller
{
    // ─── List ─────────────────────────────────────────────────

    /**
     * لیست شفت‌ها با فیلتر بر اساس آزمون.
     */
    public function index(): JsonResponse
    {
        // Authorization: requires labs.view (list shifts)
        Gate::authorize('viewAny', Shift::class);

        $shifts = Shift::with(['lab:id,name', 'exam:id,title'])
            ->when(request('exam_id'), fn($q, $v) => $q->where('exam_id', $v))
            ->when(request('lab_id'), fn($q, $v) => $q->where('lab_id', $v))
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $shifts,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * ایجاد شفت جدید.
     */
    public function store(Request $request, AuditLogService $audit): JsonResponse
    {
        // Authorization: requires labs.create
        Gate::authorize('create', Shift::class);

        $validated = $request->validate([
            'exam_id'    => 'required|exists:exams,id',
            'name'       => 'required|string|max:100',
            'start_time' => 'required|date',
            'end_time'   => 'required|date|after:start_time',
            'lab_id'     => 'required|exists:labs,id',
            'capacity'   => 'required|integer|min:1|max:500',
        ]);

        // ایجاد Shift و ثبت audit در یک تراکنش؛ شکست audit کل ایجاد را برمی‌گرداند.
        $shift = DB::transaction(function () use ($validated, $audit) {
            $newRecord = Shift::create($validated);

            $audit->created('shifts', 'Shift:created', $newRecord);

            return $newRecord;
        });

        return response()->json([
            'success' => true,
            'message' => 'شفت با موفقیت ایجاد شد.',
            'data'    => $shift->load(['lab:id,name', 'exam:id,title']),
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * جزئیات یک شفت با تعداد متقاضیان تخصیص‌یافته.
     */
    public function show(Shift $shift): JsonResponse
    {
        // Authorization: requires labs.view (show shift)
        Gate::authorize('view', Shift::class);

        $shift->load(['lab:id,name,location', 'exam:id,title']);
        $shift->loadCount('candidateAssignments');

        return response()->json([
            'success' => true,
            'data'    => $shift,
        ]);
    }

    // ─── Update ───────────────────────────────────────────────

    /**
     * ویرایش اطلاعات شفت.
     */
    public function update(Request $request, Shift $shift, AuditLogService $audit): JsonResponse
    {
        // Authorization: requires labs.update
        Gate::authorize('update', Shift::class);

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'start_time' => 'sometimes|date',
            'end_time'   => 'sometimes|date',
            'lab_id'     => 'sometimes|exists:labs,id',
            'capacity'   => 'sometimes|integer|min:1|max:500',
        ]);

        // Gap #6: compare against the effective values (sent field, otherwise the stored one)
        if (isset($validated['start_time']) || isset($validated['end_time'])) {
            $effectiveStart = isset($validated['start_time']) ? Carbon::parse($validated['start_time']) : $shift->start_time;
            $effectiveEnd   = isset($validated['end_time'])   ? Carbon::parse($validated['end_time'])   : $shift->end_time;

            if ($effectiveEnd->lessThanOrEqualTo($effectiveStart)) {
                throw ValidationException::withMessages([
                    'end_time' => ['زمان پایان باید بعد از زمان شروع باشد.'],
                ]);
            }
        }

        // Gaps #5 / #18: keep the shift consistent with its assignments
        $assignedCount = $shift->candidateAssignments()->count();

        if (isset($validated['lab_id']) && (int) $validated['lab_id'] !== (int) $shift->lab_id && $assignedCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'لابراتوار شفتی که متقاضی تخصیص‌یافته دارد قابل تغییر نیست.',
            ], 422);
        }

        if (isset($validated['capacity']) && (int) $validated['capacity'] < $assignedCount) {
            return response()->json([
                'success' => false,
                'message' => "ظرفیت نمی‌تواند کمتر از تعداد متقاضیان تخصیص‌یافته ({$assignedCount}) باشد.",
            ], 422);
        }

        // snapshot قبل از ویرایش + ثبت audit در همان تراکنش؛ شکست audit کل ویرایش را برمی‌گرداند.
        DB::transaction(function () use ($shift, $validated, $audit) {
            $before = $audit->snapshot($shift);

            $shift->update($validated);

            $audit->updated('shifts', 'Shift:updated', $shift, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'شفت با موفقیت ویرایش شد.',
            'data'    => $shift->fresh()->load(['lab:id,name', 'exam:id,title']),
        ]);
    }

    // ─── Destroy ──────────────────────────────────────────────

    /**
     * حذف شفت.
     * شفتی که متقاضی تخصیص‌یافته دارد قابل حذف نیست.
     */
    public function destroy(Shift $shift, AuditLogService $audit): JsonResponse
    {
        // Authorization: requires labs.delete
        Gate::authorize('delete', Shift::class);

        $hasAssignments = $shift->candidateAssignments()->exists();

        if ($hasAssignments) {
            return response()->json([
                'success' => false,
                'message' => 'این شفت دارای متقاضی تخصیص‌یافته است و قابل حذف نیست.',
            ], 422);
        }

        // snapshot قبل از حذف + ثبت audit در همان تراکنش.
        DB::transaction(function () use ($shift, $audit) {
            $before = $audit->snapshot($shift);

            $shift->delete();

            $audit->deleted('shifts', 'Shift:deleted', $shift, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'شفت با موفقیت حذف شد.',
        ]);
    }
}
