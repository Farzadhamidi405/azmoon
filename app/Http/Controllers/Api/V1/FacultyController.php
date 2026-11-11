<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faculty\StoreFacultyRequest;
use App\Http\Requests\Faculty\UpdateFacultyRequest;
use App\Models\Faculty;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * FacultyController — مدیریت دانشکده‌ها.
 * بند 60.2 SRS — چندمضمونی/چنددانشکده‌ای.
 */
class FacultyController extends Controller
{
    // ─── Index ────────────────────────────────────────────────

    /**
     * لیست تمام دانشکده‌ها.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Faculty::class);

        $faculties = Faculty::withCount('subjects')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $faculties,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * ایجاد دانشکده جدید.
     * در Audit Log ثبت می‌شود.
     */
    public function store(StoreFacultyRequest $request): JsonResponse
    {
        $faculty = DB::transaction(function () use ($request) {
            $faculty = Faculty::create([
                'name' => $request->name,
                'code' => $request->code,
            ]);

            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'Faculty:created',
                'module'      => 'faculties',
                'target_type' => 'Faculty',
                'target_id'   => $faculty->id,
                'new_value'   => json_encode([
                    'name' => $faculty->name,
                    'code' => $faculty->code,
                ]),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);

            return $faculty;
        });

        return response()->json([
            'success' => true,
            'message' => 'دانشکده با موفقیت ایجاد شد.',
            'data'    => $faculty,
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * مشاهده جزئیات یک دانشکده.
     */
    public function show(Faculty $faculty): JsonResponse
    {
        Gate::authorize('view', $faculty);

        $faculty->loadCount('subjects');

        return response()->json([
            'success' => true,
            'data'    => $faculty,
        ]);
    }

    // ─── Update ───────────────────────────────────────────────

    /**
     * ویرایش دانشکده.
     * در Audit Log ثبت می‌شود.
     */
    public function update(UpdateFacultyRequest $request, Faculty $faculty): JsonResponse
    {
        $oldValues = [
            'name' => $faculty->name,
            'code' => $faculty->code,
        ];

        DB::transaction(function () use ($request, $faculty, $oldValues) {
            $data = $request->only(['name', 'code']);

            $faculty->update($data);

            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'Faculty:updated',
                'module'      => 'faculties',
                'target_type' => 'Faculty',
                'target_id'   => $faculty->id,
                'old_value'   => json_encode($oldValues),
                'new_value'   => json_encode($data),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'دانشکده با موفقیت به‌روز شد.',
            'data'    => $faculty->fresh(),
        ]);
    }

    // ─── Destroy ──────────────────────────────────────────────

    /**
     * حذف دانشکده — فقط در صورت نبود مضمون وابسته مجاز است.
     * در Audit Log ثبت می‌شود.
     */
    public function destroy(Faculty $faculty): JsonResponse
    {
        Gate::authorize('delete', $faculty);

        if ($faculty->subjects()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این دانشکده دارای مضامین وابسته است و قابل حذف نیست.',
            ], 422);
        }

        DB::transaction(function () use ($faculty) {
            DB::table('audit_logs')->insert([
                'user_id'     => request()->user()->id,
                'role'        => request()->user()->role?->name,
                'action'      => 'Faculty:deleted',
                'module'      => 'faculties',
                'target_type' => 'Faculty',
                'target_id'   => $faculty->id,
                'old_value'   => json_encode([
                    'name' => $faculty->name,
                    'code' => $faculty->code,
                ]),
                'ip_address'  => request()->ip(),
                'user_agent'  => request()->userAgent(),
                'created_at'  => now(),
            ]);

            $faculty->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'دانشکده با موفقیت حذف شد.',
        ]);
    }
}