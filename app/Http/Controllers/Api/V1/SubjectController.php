<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\StoreSubjectRequest;
use App\Http\Requests\Subject\UpdateSubjectRequest;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SubjectController — مدیریت مضامین.
 * بند 60.2 و 60.6 SRS — چندمضمونی و دسترسی مضمون‌محور.
 */
class SubjectController extends Controller
{
    // ─── Index ────────────────────────────────────────────────

    /**
     * لیست مضامین.
     * Super Admin و Exam Administrator همه مضامین را می‌بینند.
     * سایر نقش‌ها فقط مضامین تخصیص‌یافته به خودشان را می‌بینند (بند 60.6).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Subject::class);

        $user = Auth::guard('admin')->user();

        $query = Subject::with('faculty')
            ->when($request->faculty_id, fn ($q) => $q->where('faculty_id', $request->faculty_id))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        if (!in_array($user->role?->name, ['Super Admin', 'Exam Administrator'])) {
            $allowedIds = $user->subjectAccess()->pluck('subject_id');
            $query->whereIn('id', $allowedIds);
        }

        $subjects = $query->get();

        return response()->json([
            'success' => true,
            'data'    => $subjects,
        ]);
    }

    // ─── Store ────────────────────────────────────────────────

    /**
     * ایجاد مضمون جدید.
     * در Audit Log ثبت می‌شود.
     */
    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = DB::transaction(function () use ($request) {
            $subject = Subject::create([
                'name'       => $request->name,
                'code'       => $request->code,
                'faculty_id' => $request->faculty_id,
                'is_active'  => $request->boolean('is_active', true),
                'created_by' => $request->user()->id,
            ]);

            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'Subject:created',
                'module'      => 'subjects',
                'target_type' => 'Subject',
                'target_id'   => $subject->id,
                'new_value'   => json_encode([
                    'name'       => $subject->name,
                    'code'       => $subject->code,
                    'faculty_id' => $subject->faculty_id,
                    'is_active'  => $subject->is_active,
                ]),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);

            return $subject;
        });

        $subject->load('faculty');

        return response()->json([
            'success' => true,
            'message' => 'مضمون با موفقیت ایجاد شد.',
            'data'    => $subject,
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * مشاهده جزئیات یک مضمون.
     */
    public function show(Subject $subject): JsonResponse
    {
        Gate::authorize('view', $subject);

        $subject->load('faculty');

        return response()->json([
            'success' => true,
            'data'    => $subject,
        ]);
    }

    // ─── Update ───────────────────────────────────────────────

    /**
     * ویرایش مضمون (شامل فعال/غیرفعال‌سازی).
     * در Audit Log ثبت می‌شود.
     */
    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $oldValues = [
            'name'       => $subject->name,
            'code'       => $subject->code,
            'faculty_id' => $subject->faculty_id,
            'is_active'  => $subject->is_active,
        ];

        DB::transaction(function () use ($request, $subject, $oldValues) {
            $data = $request->only(['name', 'code', 'faculty_id', 'is_active']);

            $subject->update($data);

            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id,
                'role'        => $request->user()->role?->name,
                'action'      => 'Subject:updated',
                'module'      => 'subjects',
                'target_type' => 'Subject',
                'target_id'   => $subject->id,
                'old_value'   => json_encode($oldValues),
                'new_value'   => json_encode($data),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'مضمون با موفقیت به‌روز شد.',
            'data'    => $subject->fresh('faculty'),
        ]);
    }

    // ─── Destroy ──────────────────────────────────────────────

    /**
     * حذف مضمون — فقط در صورت نبود آزمون/سوال/متن وابسته مجاز است.
     * در Audit Log ثبت می‌شود.
     */
    public function destroy(Subject $subject): JsonResponse
    {
        Gate::authorize('delete', $subject);

        if ($subject->exams()->exists() || $subject->questions()->exists() || $subject->passages()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این مضمون دارای آزمون یا سوال وابسته است و قابل حذف نیست.',
            ], 422);
        }

        DB::transaction(function () use ($subject) {
            DB::table('audit_logs')->insert([
                'user_id'     => request()->user()->id,
                'role'        => request()->user()->role?->name,
                'action'      => 'Subject:deleted',
                'module'      => 'subjects',
                'target_type' => 'Subject',
                'target_id'   => $subject->id,
                'old_value'   => json_encode([
                    'name'       => $subject->name,
                    'code'       => $subject->code,
                    'faculty_id' => $subject->faculty_id,
                ]),
                'ip_address'  => request()->ip(),
                'user_agent'  => request()->userAgent(),
                'created_at'  => now(),
            ]);

            $subject->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'مضمون با موفقیت حذف شد.',
        ]);
    }
}