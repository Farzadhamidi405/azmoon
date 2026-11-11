<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use App\Models\Exam;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * مدیریت آزمون‌ها — ایجاد، ویرایش، تغییر وضعیت، حذف.
 * تمام منطق حساس (وضعیت، دسترسی) در Backend کنترل می‌شود.
 * هر متد از Gate برای کنترل دسترسی بر اساس ExamPolicy استفاده می‌کند.
 */
class ExamController extends Controller
{
    private const EXAM_COMPLETED_NOTIFICATION_DEDUPE_SECONDS = 600;

    /**
     * لیست آزمون‌ها با فیلتر و صفحه‌بندی.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Exam::class);

        $query = Exam::with(['subject', 'creator'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->subject_id, fn($q) => $q->where('subject_id', $request->subject_id))
            ->orderByDesc('created_at');

        $exams = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data'    => $exams,
        ]);
    }

    /**
     * نمایش یک آزمون با جزئیات کامل.
     */
    public function show(Exam $exam)
    {
        Gate::authorize('view', $exam);

        $exam->load(['subject', 'creator', 'approver', 'sections.questionPool']);

        return response()->json([
            'success' => true,
            'data'    => $exam,
        ]);
    }

    /**
     * ایجاد آزمون جدید — وضعیت اولیه همیشه draft است.
     */
    public function store(Request $request, AuditLogService $audit)
    {
        Gate::authorize('create', Exam::class);

        $validated = $request->validate([
            'subject_id'              => ['required', 'exists:subjects,id'],
            'title'                   => ['required', 'string', 'max:255'],
            'description'             => ['nullable', 'string'],
            'duration_minutes'        => ['required', 'integer', 'min:1', 'max:480'],
            'total_marks'             => ['required', 'numeric', 'min:1'],
            'pass_marks'              => ['required', 'numeric', 'min:1', 'lte:total_marks'],
            'randomize_questions'     => ['boolean'],
            'randomize_options'       => ['boolean'],
            'allow_back_navigation'   => ['boolean'],
            'show_result_immediately' => ['boolean'],
            'default_calendar'        => ['nullable', Rule::in(['shamsi', 'qamari', 'miladi'])],
        ]);

        $validated['status']     = 'draft';
        $validated['created_by'] = Auth::id();

        // ایجاد Exam و ثبت audit در یک تراکنش؛ شکست audit کل ایجاد را برمی‌گرداند.
        $exam = DB::transaction(function () use ($validated, $audit) {
            $newRecord = Exam::create($validated);

            $audit->created('exams', 'Exam:created', $newRecord);

            return $newRecord;
        });
        $exam->load(['subject', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'آزمون با موفقیت ایجاد شد.',
            'data'    => $exam,
        ], 201);
    }

    /**
     * ویرایش آزمون — فقط در وضعیت draft یا under_review مجاز است.
     */
    public function update(Request $request, Exam $exam, AuditLogService $audit)
    {
        Gate::authorize('update', $exam);

        $validated = $request->validate([
            'title'                   => ['sometimes', 'string', 'max:255'],
            'description'             => ['nullable', 'string'],
            'duration_minutes'        => ['sometimes', 'integer', 'min:1', 'max:480'],
            'total_marks'             => ['sometimes', 'numeric', 'min:1'],
            'pass_marks'              => ['sometimes', 'numeric', 'min:1'],
            'randomize_questions'     => ['boolean'],
            'randomize_options'       => ['boolean'],
            'allow_back_navigation'   => ['boolean'],
            'show_result_immediately' => ['boolean'],
            'default_calendar'        => ['nullable', Rule::in(['shamsi', 'qamari', 'miladi'])],
        ]);

        // snapshot قبل از ویرایش + ثبت audit در همان تراکنش؛ شکست audit کل ویرایش را برمی‌گرداند.
        DB::transaction(function () use ($exam, $validated, $audit) {
            $before = $audit->snapshot($exam);

            $exam->update($validated);

            $audit->updated('exams', 'Exam:updated', $exam, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'آزمون با موفقیت ویرایش شد.',
            'data'    => $exam->fresh(['subject', 'creator']),
        ]);
    }

    /**
     * تغییر وضعیت آزمون — چرخه عمر کنترل‌شده.
     */
    public function changeStatus(Request $request, Exam $exam, NotificationService $notificationService, AuditLogService $audit)
    {
        Gate::authorize('changeStatus', $exam);

        $request->validate([
            'status' => ['required', Rule::in(Exam::STATUSES)],
        ]);

        $newStatus = $request->status;
        $oldStatus = $exam->status;

        $allowed = [
            'draft'        => ['under_review'],
            'under_review' => ['draft', 'approved'],
            'approved'     => ['scheduled', 'published'],
            'scheduled'    => ['published'],
            'published'    => ['running', 'archived'],
            'running'      => ['completed'],
            'completed'    => ['reviewed', 'archived'],
            'reviewed'     => ['archived'],
        ];

        if (!in_array($newStatus, $allowed[$oldStatus] ?? [])) {
            return response()->json([
                'success' => false,
                'message' => "انتقال از «{$oldStatus}» به «{$newStatus}» مجاز نیست.",
            ], 422);
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'published') {
            $updateData['published_at'] = now();
            $updateData['approved_by']  = Auth::id();
        }

        // وضعیت قبلی، تغییر وضعیت و ثبت audit در یک تراکنش.
        $previousStatus = (string) $exam->status;

        DB::transaction(function () use ($exam, $updateData, $audit, $previousStatus) {
            $exam->update($updateData);

            $audit->statusChanged(
                'exams',
                'Exam:status_changed',
                $exam,
                $previousStatus,
                (string) $exam->status,
                array_diff_key($updateData, ['status' => true])
            );
        });

        if ($newStatus === 'completed') {
            $this->notifyExamCompleted($notificationService, $exam);
        }

        return response()->json([
            'success' => true,
            'message' => "وضعیت آزمون به «{$newStatus}» تغییر کرد.",
            'data'    => $exam->fresh(),
        ]);
    }

    /**
     * Notify authorized users that the exam reached the completed status.
     * Errors are only reported so the status change response is never broken.
     */
    private function notifyExamCompleted(NotificationService $notificationService, Exam $exam): void
    {
        try {
            $actorId = Auth::id();

            $notificationService->dispatch(
                NotificationType::ExamCompleted,
                'پایان آزمون',
                "وضعیت آزمون شماره {$exam->id} به «پایان‌یافته» تغییر کرد.",
                'exam',
                $exam->id,
                $actorId !== null ? (int) $actorId : null,
                self::EXAM_COMPLETED_NOTIFICATION_DEDUPE_SECONDS
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * حذف آزمون — فقط در وضعیت draft مجاز است.
     */
    public function destroy(Exam $exam, AuditLogService $audit)
    {
        Gate::authorize('delete', $exam);

        // snapshot قبل از حذف + ثبت audit در همان تراکنش.
        DB::transaction(function () use ($exam, $audit) {
            $before = $audit->snapshot($exam);

            $exam->delete();

            $audit->deleted('exams', 'Exam:deleted', $exam, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'آزمون با موفقیت حذف شد.',
        ]);
    }
}
