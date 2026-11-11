<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Incident\StoreIncidentRequest;
use App\Http\Requests\Incident\UpdateIncidentRequest;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * IncidentController — مدیریت حوادث جلسه امتحان.
 * شامل: ثبت، مشاهده، ویرایش، و Resolve حوادث.
 * بند 26 SRS — Section 12.
 */
class IncidentController extends Controller
{
    // ─── Index ────────────────────────────────────────────────

    /**
     * لیست حوادث با فیلتر بر اساس وضعیت، متقاضی، کامپیوتر.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Incident::class);

        $query = Incident::with([
            'candidate:id,name,candidate_code',
            'computer:id,computer_code,ip_address',
            'attempt:id,status',
            'responsibleUser:id,name,username',
        ]);

        // فیلتر بر اساس وضعیت
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // فیلتر بر اساس متقاضی
        if ($request->filled('candidate_id')) {
            $query->where('candidate_id', $request->candidate_id);
        }

        // فیلتر بر اساس کامپیوتر
        if ($request->filled('computer_id')) {
            $query->where('computer_id', $request->computer_id);
        }

        // فیلتر بر اساس تاریخ
        if ($request->filled('date')) {
            $query->whereDate('occurred_at', $request->date);
        }

        $incidents = $query->orderByDesc('occurred_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $incidents,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * ثبت حادثه جدید.
     * Authorization در StoreIncidentRequest انجام می‌شود.
     */
    public function store(StoreIncidentRequest $request, AuditLogService $audit): JsonResponse
    {
        // ایجاد Incident و ثبت audit در یک تراکنش؛ شکست audit کل ایجاد را برمی‌گرداند.
        $incident = DB::transaction(function () use ($request, $audit) {
            $newRecord = Incident::create($request->validated());

            $audit->created('incidents', 'Incident:created', $newRecord);

            return $newRecord;
        });

        $incident->load([
            'candidate:id,name,candidate_code',
            'computer:id,computer_code',
            'attempt:id,status',
            'responsibleUser:id,name,username',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'حادثه با موفقیت ثبت شد.',
            'data'    => $incident,
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * جزئیات یک حادثه.
     */
    public function show(Incident $incident): JsonResponse
    {
        Gate::authorize('view', $incident);

        $incident->load([
            'candidate:id,name,candidate_code',
            'computer:id,computer_code,ip_address,hostname',
            'attempt:id,status,started_at,submitted_at',
            'responsibleUser:id,name,username',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $incident,
        ]);
    }

    // ─── Update ───────────────────────────────────────────────

    /**
     * ویرایش اطلاعات حادثه.
     * فقط حوادث open قابل ویرایش هستند — Policy این را کنترل می‌کند.
     * Authorization در UpdateIncidentRequest انجام می‌شود.
     */
    public function update(UpdateIncidentRequest $request, Incident $incident, AuditLogService $audit): JsonResponse
    {
        // snapshot قبل از ویرایش + ثبت audit در همان تراکنش؛ شکست audit کل ویرایش را برمی‌گرداند.
        DB::transaction(function () use ($request, $incident, $audit) {
            $before = $audit->snapshot($incident);

            $incident->update($request->validated());

            $audit->updated('incidents', 'Incident:updated', $incident, $before);
        });

        $incident->load([
            'candidate:id,name,candidate_code',
            'computer:id,computer_code',
            'attempt:id,status',
            'responsibleUser:id,name,username',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'حادثه با موفقیت ویرایش شد.',
            'data'    => $incident->fresh(),
        ]);
    }

    // ─── Resolve ──────────────────────────────────────────────

    

    /**
     * تغییر وضعیت حادثه به resolved.
     * resolved_at به‌صورت خودکار ثبت می‌شود.
     */
   // ─── Resolve ──────────────────────────────────────────────

    /**
     * تغییر وضعیت حادثه به resolved.
     * چک isResolved() قبل از Gate انجام می‌شود تا 422 برگردد نه 403.
     * resolved_at به‌صورت خودکار ثبت می‌شود.
     */
    public function resolve(Incident $incident, AuditLogService $audit): JsonResponse
    {
        // ابتدا چک می‌کنیم آیا قبلاً resolved شده — قبل از Policy چک می‌شود
        if ($incident->isResolved()) {
            return response()->json([
                'success' => false,
                'message' => 'این حادثه قبلاً حل شده است.',
            ], 422);
        }

        Gate::authorize('resolve', $incident);

        // تغییر وضعیت به حل‌شده و ثبت audit در یک تراکنش (بعد از 422 و Gate بدون تغییر).
        DB::transaction(function () use ($incident, $audit) {
            $oldStatus = (string) $incident->status;

            $incident->update([
                'status' => 'resolved',
                'resolved_at' => now(),
            ]);

            $audit->record(
                'incidents',
                'Incident:resolved',
                'Incident',
                $incident->id,
                ['status' => $oldStatus],
                ['status' => 'resolved', 'resolved_at' => (string) $incident->resolved_at],
                ['status', 'resolved_at']
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'حادثه با موفقیت حل‌شده علامت‌گذاری شد.',
            'data'    => $incident->fresh(),
        ]);
    }
}