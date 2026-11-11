<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Models\Computer;
use App\Models\Lab;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ComputerController — مدیریت کامپیوترهای لابراتوار.
 * شامل: لیست، ایجاد، ویرایش، حذف، و تغییر وضعیت.
 * بند 23 SRS — Section 7.
 */
class ComputerController extends Controller
{
    // ─── List ─────────────────────────────────────────────────

    /**
     * لیست همه کامپیوترها با فیلتر بر اساس لابراتوار و وضعیت.
     */
    public function index(): JsonResponse
    {
        // Authorization: requires labs.view (list computers)
        Gate::authorize('viewAny', Computer::class);

        $computers = Computer::query()
            ->with('lab:id,name')
            ->when(request('lab_id'), fn($q, $v) => $q->where('lab_id', $v))
            ->when(request('status'), fn($q, $v) => $q->where('status', $v))
            ->when(
                request('search'),
                fn($q, $s) => $q->where(function ($w) use ($s) {
                    // Gap #4: group the OR conditions so they cannot bypass the lab_id / status filters
                    $w->where('computer_code', 'like', "%{$s}%")
                        ->orWhere('hostname', 'like', "%{$s}%")
                        ->orWhere('ip_address', 'like', "%{$s}%");
                })
            )
            ->orderBy('lab_id')
            ->orderBy('computer_code')
            ->paginate(min(max((int) (request('per_page') ?? 50), 1), 100)); // Gap #8: 1..100, default 50

        return response()->json([
            'success' => true,
            'data'    => $computers,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * افزودن کامپیوتر جدید به لابراتوار.
     */
    public function store(Request $request, AuditLogService $audit): JsonResponse
    {
        // Authorization: requires labs.create
        Gate::authorize('create', Computer::class);

        $validated = $request->validate([
            'lab_id'          => 'required|exists:labs,id',
            'computer_code'   => 'required|string|max:50|unique:computers,computer_code',
            'hostname'        => 'nullable|string|max:100',
            'ip_address'      => 'required|string|max:45',
            'mac_address'     => 'nullable|string|max:17',
            'os'              => 'nullable|string|max:100',
            'browser'         => 'nullable|string|max:100',
            'agent_version'   => 'nullable|string|max:20',
            'status'          => 'sometimes|in:online,offline,maintenance,locked',
        ]);

        // وضعیت پیش‌فرض offline است تا Agent اول Heartbeat بزند
        $validated['status'] = $validated['status'] ?? 'offline';

        // ایجاد Computer و ثبت audit در یک تراکنش؛ شکست audit کل ایجاد را برمی‌گرداند.
        $computer = DB::transaction(function () use ($validated, $audit) {
            $newRecord = Computer::create($validated);

            $audit->created('computers', 'Computer:created', $newRecord);

            return $newRecord;
        });

        return response()->json([
            'success' => true,
            'message' => 'کامپیوتر با موفقیت اضافه شد.',
            'data'    => $computer->load('lab:id,name'),
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * جزئیات یک کامپیوتر.
     */
    public function show(Computer $computer): JsonResponse
    {
        // Authorization: requires labs.view (show computer)
        Gate::authorize('view', Computer::class);

        $computer->load('lab:id,name,location');

        return response()->json([
            'success' => true,
            'data'    => $computer,
        ]);
    }

    // ─── Update ───────────────────────────────────────────────

    /**
     * ویرایش اطلاعات کامپیوتر.
     */
    public function update(Request $request, Computer $computer, AuditLogService $audit): JsonResponse
    {
        // Authorization: requires labs.update
        Gate::authorize('update', Computer::class);

        $validated = $request->validate([
            'lab_id'        => 'sometimes|exists:labs,id',
            'computer_code' => 'sometimes|string|max:50|unique:computers,computer_code,' . $computer->id,
            'hostname'      => 'nullable|string|max:100',
            'ip_address'    => 'sometimes|string|max:45',
            'mac_address'   => 'nullable|string|max:17',
            'os'            => 'nullable|string|max:100',
            'browser'       => 'nullable|string|max:100',
            'agent_version' => 'nullable|string|max:20',
        ]);

        // Gap #5: a computer in an exam, or referenced by an assignment, cannot be moved to another lab
        if (isset($validated['lab_id']) && (int) $validated['lab_id'] !== (int) $computer->lab_id
            && ($computer->status === 'exam' || $computer->candidateAssignments()->exists())) {
            return response()->json([
                'success' => false,
                'message' => 'لابراتوار کامپیوتری که در حال امتحان است یا تخصیص دارد قابل تغییر نیست.',
            ], 422);
        }

        // snapshot قبل از ویرایش + ثبت audit در همان تراکنش؛ شکست audit کل ویرایش را برمی‌گرداند.
        DB::transaction(function () use ($computer, $validated, $audit) {
            $before = $audit->snapshot($computer);

            $computer->update($validated);

            $audit->updated('computers', 'Computer:updated', $computer, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات کامپیوتر ویرایش شد.',
            'data'    => $computer->fresh()->load('lab:id,name'),
        ]);
    }

    // ─── Destroy ──────────────────────────────────────────────

    /**
     * حذف کامپیوتر.
     * کامپیوتری که در حال امتحان است قابل حذف نیست.
     */
    public function destroy(Computer $computer, AuditLogService $audit): JsonResponse
    {
        // Authorization: requires labs.delete
        Gate::authorize('delete', Computer::class);

        if ($computer->status === 'exam') {
            return response()->json([
                'success' => false,
                'message' => 'این کامپیوتر در حال امتحان است و قابل حذف نیست.',
            ], 422);
        }

        // Gap #17: a computer used by an assignment must not be deleted (FK would silently null the assignment)
        if ($computer->candidateAssignments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این کامپیوتر در تخصیص متقاضی استفاده شده و قابل حذف نیست؛ در صورت نیاز آن را offline یا maintenance کنید.',
            ], 422);
        }

        // Change 11: an incident referencing this computer must keep its reference (FK is nullOnDelete)
        if (DB::table('incidents')->where('computer_id', $computer->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این کامپیوتر در گزارش حادثه استفاده شده و قابل حذف نیست.',
            ], 422);
        }

        try {
            // snapshot قبل از حذف + ثبت audit در همان تراکنش.
            DB::transaction(function () use ($computer, $audit) {
                $before = $audit->snapshot($computer);

                $computer->delete();

                $audit->deleted('computers', 'Computer:deleted', $computer, $before);
            });
        } catch (QueryException $e) {
            // Change 10: only a MySQL Foreign Key violation (SQLSTATE 23000 + code 1451: attempts / session transfers) becomes 422
            if ((string) ($e->errorInfo[0] ?? '') === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1451) {
                return response()->json([
                    'success' => false,
                    'message' => 'این کامپیوتر در سوابق آزمون یا انتقال نشست استفاده شده و قابل حذف نیست.',
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'کامپیوتر با موفقیت حذف شد.',
        ]);
    }

    // ─── Update Status ────────────────────────────────────────

    /**
     * تغییر وضعیت کامپیوتر توسط Admin یا Lab Manager.
     * وضعیت‌های مجاز: online, offline, maintenance, locked
     * وضعیت exam فقط توسط سیستم تغییر می‌کند — نه دستی.
     */
    public function updateStatus(Request $request, Computer $computer, AuditLogService $audit): JsonResponse
    {
        // Authorization: requires labs.update (status change)
        Gate::authorize('updateStatus', Computer::class);

        $validated = $request->validate([
            'status' => 'required|in:online,offline,maintenance,locked',
        ]);

        // وضعیت exam فقط توسط سیستم تنظیم می‌شود
        if ($computer->status === 'exam') {
            return response()->json([
                'success' => false,
                'message' => 'کامپیوتری که در حال امتحان است را نمی‌توان دستی تغییر وضعیت داد.',
            ], 422);
        }

        // وضعیت قبلی، تغییر وضعیت و ثبت audit در یک تراکنش.
        $previousStatus = (string) $computer->status;

        DB::transaction(function () use ($computer, $validated, $audit, $previousStatus) {
            $computer->update(['status' => $validated['status']]);

            $audit->statusChanged(
                'computers',
                'Computer:status_changed',
                $computer,
                $previousStatus,
                (string) $computer->status,
                []
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'وضعیت کامپیوتر به «' . $validated['status'] . '» تغییر یافت.',
            'data'    => $computer->fresh(),
        ]);
    }
}
