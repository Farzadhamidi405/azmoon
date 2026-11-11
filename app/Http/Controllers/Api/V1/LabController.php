<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lab\StoreLabRequest;
use App\Http\Requests\Lab\UpdateLabRequest;
use App\Http\Requests\Lab\StoreComputerRequest;
use App\Http\Requests\Lab\StoreShiftRequest;
use App\Models\Computer;
use App\Models\Lab;
use App\Models\Shift;
use App\Services\AutoAssignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * کنترلر مدیریت لابراتوارها، کامپیوترها و شیفت‌ها.
 */
class LabController extends Controller
{
    /**
     * لیست تمام لابراتوارها همراه با تعداد کامپیوترها.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Lab::class);

        $labs = Lab::withCount(Lab::computerCounters())->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => $labs,
        ]);
    }

    /**
     * ایجاد لابراتوار جدید.
     */
    public function store(StoreLabRequest $request): JsonResponse
    {
        Gate::authorize('create', Lab::class);

        $lab = Lab::create($request->validated());

        // Audit: record the new lab (module/old_value/new_value are the real audit_logs columns).
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'role' => $request->user()->role?->name,
            'action' => 'lab.created',
            'module' => 'labs',
            'target_type' => 'Lab',
            'target_id' => $lab->id,
            'old_value' => null,
            'new_value' => json_encode(['name' => $lab->name, 'location' => $lab->location]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'لابراتوار با موفقیت ایجاد شد.',
            'data'    => $lab,
        ], 201);
    }

    /**
     * مشاهده جزئیات یک لابراتوار همراه با کامپیوترها و شیفت‌ها.
     */
    public function show(Lab $lab): JsonResponse
    {
        Gate::authorize('view', $lab);

        $lab->load(['computers' => fn ($q) => $q->orderBy('computer_code'), 'shifts']);
        $lab->loadCount(Lab::computerCounters());

        return response()->json([
            'success' => true,
            'data'    => $lab,
        ]);
    }

    /**
     * ویرایش لابراتوار.
     */
    public function update(UpdateLabRequest $request, Lab $lab): JsonResponse
    {
        Gate::authorize('update', $lab);

        // Snapshot of the old values of the fields being changed (for the audit log).
        $auditOld = array_intersect_key($lab->getAttributes(), array_flip(['name', 'location']), $request->validated());

        $lab->update($request->validated());

        // Audit: record old and new values of the changed fields.
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'role' => $request->user()->role?->name,
            'action' => 'lab.updated',
            'module' => 'labs',
            'target_type' => 'Lab',
            'target_id' => $lab->id,
            'old_value' => json_encode($auditOld),
            'new_value' => json_encode($request->validated()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'لابراتوار با موفقیت بروزرسانی شد.',
            'data'    => $lab->fresh(),
        ]);
    }

    /**
     * حذف لابراتوار (فقط در صورت نداشتن جلسه فعال).
     */
    public function destroy(Request $request, Lab $lab): JsonResponse
    {
        Gate::authorize('delete', $lab);

        $activeExams = $lab->computers()->where('status', 'exam')->count();
        if ($activeExams > 0) {
            return response()->json([
                'success' => false,
                'message' => 'امکان حذف لابراتوار در حین برگزاری آزمون وجود ندارد.',
            ], 422);
        }

        // Block deletion while related records exist (FK protection): computers, shifts, candidate assignments.
        $blockedMessage = "\u{0627}\u{0645}\u{06A9}\u{0627}\u{0646} \u{062D}\u{0630}\u{0641} \u{0627}\u{0644}\u{0628}\u{0631}\u{0627}\u{062A}\u{0648}\u{0627}\u{0631} \u{0648}\u{062C}\u{0648}\u{062F} \u{0646}\u{062F}\u{0627}\u{0631}\u{062F} \u{0686}\u{0648}\u{0646} \u{06A9}\u{0627}\u{0645}\u{067E}\u{06CC}\u{0648}\u{062A}\u{0631}\u{060C} \u{0634}\u{06CC}\u{0641}\u{062A} \u{06CC}\u{0627} \u{062A}\u{062E}\u{0635}\u{06CC}\u{0635} \u{0628}\u{0647} \u{0622}\u{0646} \u{0648}\u{0627}\u{0628}\u{0633}\u{062A}\u{0647} \u{0627}\u{0633}\u{062A}.";
        if ($lab->computers()->exists() || $lab->shifts()->exists() || $lab->candidateAssignments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => $blockedMessage,
            ], 422);
        }

        $labName = $lab->name;
        $labId = $lab->id;
        try {
            $lab->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // Safety net: a foreign key violation (SQLSTATE 23000), e.g. from a concurrent insert, is a 422 and not a 500.
            if ((string) $e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => $blockedMessage,
                ], 422);
            }

            throw $e;
        }

        // Audit: record the deleted lab name.
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'role' => $request->user()->role?->name,
            'action' => 'lab.deleted',
            'module' => 'labs',
            'target_type' => 'Lab',
            'target_id' => $labId,
            'old_value' => json_encode(['name' => $labName]),
            'new_value' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'لابراتوار با موفقیت حذف شد.',
        ]);
    }

    // ─── مدیریت کامپیوترهای لابراتوار ───

    /**
     * لیست کامپیوترهای یک لابراتوار با فیلتر وضعیت.
     */
    public function computers(Request $request, Lab $lab): JsonResponse
    {
        Gate::authorize('view', $lab);

        $query = $lab->computers()->orderBy('computer_code');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }

    /**
     * افزودن کامپیوتر جدید به لابراتوار.
     */
    public function storeComputer(StoreComputerRequest $request, Lab $lab): JsonResponse
    {
        Gate::authorize('create', Computer::class);

        $computer = $lab->computers()->create($request->validated());

        DB::table('audit_logs')->insert([
            'user_id'     => $request->user()->id,
            'action'      => 'computer.created',
            'target_type' => 'Computer',
            'target_id'   => $computer->id,
            'details'     => json_encode([
                'lab_id'        => $lab->id,
                'computer_code' => $computer->computer_code,
                'ip_address'    => $computer->ip_address,
            ]),
            'ip_address'  => $request->ip(),
            'created_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'کامپیوتر با موفقیت ثبت شد.',
            'data'    => $computer,
        ], 201);
    }

    // ─── مدیریت شیفت‌ها ───

    /**
     * لیست شیفت‌های یک لابراتوار (با فیلتر آزمون یا وضعیت).
     */
    public function shifts(Request $request, Lab $lab): JsonResponse
    {
        Gate::authorize('view', $lab);

        $query = $lab->shifts()->with('exam:id,title')->orderBy('start_time', 'desc');

        if ($request->filled('exam_id')) {
            $query->where('exam_id', $request->input('exam_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }

    /**
     * ایجاد شیفت جدید در لابراتوار.
     */
    public function storeShift(StoreShiftRequest $request, Lab $lab): JsonResponse
    {
        Gate::authorize('create', Shift::class);

        $data = $request->validated();
        $data['lab_id'] = $lab->id;

        $shift = Shift::create($data);

        DB::table('audit_logs')->insert([
            'user_id'     => $request->user()->id,
            'action'      => 'shift.created',
            'target_type' => 'Shift',
            'target_id'   => $shift->id,
            'details'     => json_encode([
                'title'      => $shift->title,
                'lab_id'     => $lab->id,
                'exam_id'    => $shift->exam_id,
                'start_time' => $shift->start_time,
            ]),
            'ip_address'  => $request->ip(),
            'created_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'شیفت با موفقیت ایجاد شد.',
            'data'    => $shift,
        ], 201);
    }

    /**
     * پیش‌نمایش و اجرای تخصیص خودکار داوطلبان به کامپیوترها در یک شیفت.
     */
    public function autoAssign(Request $request, Shift $shift): JsonResponse
    {
        Gate::authorize('autoAssign', Shift::class);

        /** @var AutoAssignService $service */
        $service = app(AutoAssignService::class);

        // حالت پیش‌نمایش (کاملاً Read-Only بدون هیچ تغییر در دیتابیس)
        if (!$request->boolean('confirmed')) {
            $preview = $service->preview($shift->id);

            return response()->json([
                'success'   => true,
                'confirmed' => false,
                'mode'      => 'preview',
                'data'      => $preview,
            ]);
        }

        // حالت تأیید نهایی (اتمیک، تحت قفل و با ثبت دقیق assigned_by و assigned_at)
        $user = $request->user();
        $userId = $user?->id;
        $result = $service->confirm($shift->id, $userId);
        $count = count($result['assignments']);

        DB::table('audit_logs')->insert([
            'user_id'     => $userId,
            'role'        => $user?->role?->name,
            'action'      => 'shift.auto_assigned',
            'module'      => 'labs',
            'target_type' => 'Shift',
            'target_id'   => $shift->id,
            'old_value'   => null,
            'new_value'   => json_encode([
                'assigned_count' => $count,
                'shift_name'     => $shift->name,
            ]),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'created_at'  => now(),
        ]);

        return response()->json([
            'success'   => true,
            'confirmed' => true,
            'mode'      => 'confirmed',
            'message'   => "{$count} داوطلب با موفقیت به کامپیوترها تخصیص یافتند.",
            'assigned'  => $count,
            'data'      => $result,
        ]);
    }

    /**
     * Computers grid of one lab: lab info, status summary and the computers (one query).
     */
    public function computersGrid(Lab $lab): JsonResponse
    {
        Gate::authorize('computersGrid', $lab);

        $computers = $lab->computers()
            ->orderBy('computer_code')
            ->get(['id', 'lab_id', 'computer_code', 'hostname', 'ip_address', 'status', 'last_seen_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'lab' => ['id' => $lab->id, 'name' => $lab->name, 'location' => $lab->location],
                'summary' => Lab::summarizeStatuses($computers),
                'computers' => $computers,
            ],
        ]);
    }}
