<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Services\ExamSnapshotService;
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
            'sections'                => ['nullable', 'array', 'min:1'],
            'sections.*.name'          => ['required', 'string', 'max:100'],
            'sections.*.question_count'=> ['required', 'integer', 'min:1'],
            'sections.*.total_marks'   => ['required', 'numeric', 'min:0.5'],
            'sections.*.display_order' => ['nullable', 'integer'],
        ]);

        $sections = $validated['sections'] ?? [];
        unset($validated['sections']);

        // اعتبارسنجی همخوانی مجموع نمرات بخش‌ها با نمره کل
        if (!empty($sections)) {
            $sectionsSum = (float) array_sum(array_column($sections, 'total_marks'));
            if (abs($sectionsSum - (float) $validated['total_marks']) > 1.0) {
                return response()->json([
                    'success' => false,
                    'message' => 'مجموع نمرات بخش‌های آزمون با نمره کل آزمون برابر نیست.',
                    'errors'  => [
                        'sections' => ['مجموع نمرات بخش‌ها (' . $sectionsSum . ') باید با نمره کل (' . $validated['total_marks'] . ') برابر باشد.'],
                    ],
                ], 422);
            }
        }

        $validated['status']     = 'draft';
        $validated['created_by'] = Auth::id();

        // ایجاد Exam و ذخیره بخش‌ها در یک تراکنش اتمیک
        $exam = DB::transaction(function () use ($validated, $sections, $audit) {
            $newRecord = Exam::create($validated);

            if (!empty($sections)) {
                foreach ($sections as $index => $sec) {
                    $newRecord->sections()->create([
                        'name'           => $sec['name'],
                        'question_count' => (int) $sec['question_count'],
                        'total_marks'    => (float) $sec['total_marks'],
                        'display_order'  => (int) ($sec['display_order'] ?? ($index + 1)),
                    ]);
                }
            }

            $audit->created('exams', 'Exam:created', $newRecord);

            return $newRecord;
        });
        $exam->load(['subject', 'creator', 'sections']);

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
            'sections'                => ['nullable', 'array', 'min:1'],
            'sections.*.name'          => ['required', 'string', 'max:100'],
            'sections.*.question_count'=> ['required', 'integer', 'min:1'],
            'sections.*.total_marks'   => ['required', 'numeric', 'min:0.5'],
            'sections.*.display_order' => ['nullable', 'integer'],
        ]);

        $sections = null;
        if (array_key_exists('sections', $validated)) {
            $sections = $validated['sections'];
            unset($validated['sections']);

            $totalTargetMarks = (float) ($validated['total_marks'] ?? $exam->total_marks);
            $sectionsSum = (float) array_sum(array_column($sections, 'total_marks'));
            if (abs($sectionsSum - $totalTargetMarks) > 1.0) {
                return response()->json([
                    'success' => false,
                    'message' => 'مجموع نمرات بخش‌های آزمون با نمره کل آزمون برابر نیست.',
                    'errors'  => [
                        'sections' => ['مجموع نمرات بخش‌ها (' . $sectionsSum . ') باید با نمره کل (' . $totalTargetMarks . ') برابر باشد.'],
                    ],
                ], 422);
            }
        }

        // snapshot قبل از ویرایش + ثبت audit در همان تراکنش
        DB::transaction(function () use ($exam, $validated, $sections, $audit) {
            $before = $audit->snapshot($exam);

            $exam->update($validated);

            if ($sections !== null) {
                $exam->sections()->delete();
                foreach ($sections as $index => $sec) {
                    $exam->sections()->create([
                        'name'           => $sec['name'],
                        'question_count' => (int) $sec['question_count'],
                        'total_marks'    => (float) $sec['total_marks'],
                        'display_order'  => (int) ($sec['display_order'] ?? ($index + 1)),
                    ]);
                }
            }

            $audit->updated('exams', 'Exam:updated', $exam, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'آزمون با موفقیت ویرایش شد.',
            'data'    => $exam->fresh(['subject', 'creator', 'sections']),
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

            // ساخت و انجماد اسنپ‌شات آزمون جهت جلوگیری از خطای ۵۰۰ در زمان ورود متقاضی
            try {
                $snapshotService = app(ExamSnapshotService::class);
                $snapshotService->findOrCreateSnapshot($exam);
            } catch (Throwable $snapErr) {
                report($snapErr);
            }
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

    /**
     * دریافت وضعیت Question Pool آزمون.
     */
    public function getPool(Exam $exam): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('view', $exam);

        $exam->load('sections.questionPool');

        $data = $exam->sections->map(function ($section) use ($exam) {
            $poolCount = \App\Models\Question::where('subject_id', $exam->subject_id)
                ->where('status', 'approved')
                ->where('section_name', $section->name)
                ->count();

            if ($poolCount === 0) {
                $poolCount = \App\Models\Question::where('subject_id', $exam->subject_id)
                    ->where('status', 'approved')
                    ->count();
            }

            return [
                'id'             => $section->id,
                'section'        => $section->name,
                'total'          => max($poolCount, (int) $section->question_count),
                'selected'       => (int) $section->question_count,
                'randomize'      => (bool) ($exam->randomize_options ?? true),
                'total_marks'    => (float) $section->total_marks,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * به‌روزرسانی تنظیمات Question Pool آزمون.
     */
    public function updatePool(Request $request, Exam $exam): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('update', $exam);

        $validated = $request->validate([
            'sections'               => 'required|array',
            'sections.*.id'          => 'required|exists:exam_sections,id',
            'sections.*.selected'    => 'required|integer|min:1',
            'randomize_options'      => 'nullable|boolean',
            'randomize_questions'    => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($validated, $exam) {
            foreach ($validated['sections'] as $item) {
                $exam->sections()->where('id', $item['id'])->update([
                    'question_count' => (int) $item['selected'],
                ]);
            }

            $examUpdates = [];
            if (isset($validated['randomize_options'])) {
                $examUpdates['randomize_options'] = (bool) $validated['randomize_options'];
            }
            if (isset($validated['randomize_questions'])) {
                $examUpdates['randomize_questions'] = (bool) $validated['randomize_questions'];
            }
            if (!empty($examUpdates)) {
                $exam->update($examUpdates);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'تنظیمات Question Pool با موفقیت ذخیره شد.',
        ]);
    }
}
