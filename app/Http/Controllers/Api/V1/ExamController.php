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

        $query = Exam::with(['subject', 'creator', 'sections'])
            ->withCount(['questions', 'shifts'])
            ->when($request->search, fn($q) => $q->where('title', 'like', '%'.$request->search.'%'))
            ->when($request->title, fn($q) => $q->where('title', 'like', '%'.$request->title.'%'))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->subject_id, fn($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->date, function ($q, $date) {
                $q->whereDate('created_at', $date)
                  ->orWhereDate('published_at', $date);
            })
            ->orderByDesc('created_at');

        $exams = $query->paginate($request->per_page ?? 15);

        // غنی‌سازی رکوردهای آزمون با محاسبه تعداد سوالات و متقاضیان
        $exams->getCollection()->transform(function ($exam) {
            $sectionsSum = (int) $exam->sections->sum('question_count');
            $exam->total_questions = $exam->questions_count > 0 ? $exam->questions_count : $sectionsSum;
            return $exam;
        });

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

        $exam->load(['subject', 'creator', 'approver', 'sections.questionPool', 'questions.options']);
        $exam->loadCount('questions');

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
            'question_ids'            => ['nullable', 'array'],
            'question_ids.*'          => ['required', 'integer', 'exists:questions,id'],
            'sections'                => ['nullable', 'array', 'min:1'],
            'sections.*.name'          => ['required', 'string', 'max:100'],
            'sections.*.question_count'=> ['required', 'integer', 'min:1'],
            'sections.*.total_marks'   => ['required', 'numeric', 'min:0.5'],
            'sections.*.display_order' => ['nullable', 'integer'],
        ]);

        $sections = $validated['sections'] ?? [];
        $questionIds = $validated['question_ids'] ?? [];
        unset($validated['sections'], $validated['question_ids']);

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

        // اعتبارسنجی سوالات انتخابی در صورت وجود
        if (!empty($questionIds)) {
            $invalidQuestionsCount = \App\Models\Question::whereIn('id', $questionIds)
                ->where(function ($q) use ($validated) {
                    $q->where('subject_id', '!=', $validated['subject_id'])
                      ->orWhereNotIn('status', ['approved', 'active']);
                })
                ->count();

            if ($invalidQuestionsCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'برخی از سوالات انتخاب‌شده تایید نشده‌اند یا متعلق به این مضمون نیستند.',
                ], 422);
            }
        }

        $validated['status']     = 'draft';
        $validated['created_by'] = Auth::id();

        // ایجاد Exam و ذخیره بخش‌ها و سوالات در یک تراکنش اتمیک
        $exam = DB::transaction(function () use ($validated, $sections, $questionIds, $audit) {
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

            if (!empty($questionIds)) {
                $validQuestions = \App\Models\Question::whereIn('id', $questionIds)->get();
                $order = 1;
                $syncData = [];
                foreach ($validQuestions as $q) {
                    $syncData[$q->id] = [
                        'display_order' => $order++,
                        'score'         => $q->score ?? 1.0,
                    ];
                }
                $newRecord->questions()->sync($syncData);
            }

            $audit->created('exams', 'Exam:created', $newRecord);

            return $newRecord;
        });
        $exam->load(['subject', 'creator', 'sections', 'questions']);

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
            'question_ids'            => ['nullable', 'array'],
            'question_ids.*'          => ['required', 'integer', 'exists:questions,id'],
            'sections'                => ['nullable', 'array', 'min:1'],
            'sections.*.name'          => ['required', 'string', 'max:100'],
            'sections.*.question_count'=> ['required', 'integer', 'min:1'],
            'sections.*.total_marks'   => ['required', 'numeric', 'min:0.5'],
            'sections.*.display_order' => ['nullable', 'integer'],
        ]);

        $questionIds = null;
        if (array_key_exists('question_ids', $validated)) {
            $questionIds = $validated['question_ids'];
            unset($validated['question_ids']);

            if (!empty($questionIds)) {
                $invalidCount = \App\Models\Question::whereIn('id', $questionIds)
                    ->where(function ($q) use ($exam) {
                        $q->where('subject_id', '!=', $exam->subject_id)
                          ->orWhereNotIn('status', ['approved', 'active']);
                    })
                    ->count();

                if ($invalidCount > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'برخی از سوالات انتخاب‌شده تایید نشده‌اند یا متعلق به مضمون این آزمون نیستند.',
                    ], 422);
                }
            }
        }

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
        DB::transaction(function () use ($exam, $validated, $sections, $questionIds, $audit) {
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

            if ($questionIds !== null) {
                $validQuestions = \App\Models\Question::whereIn('id', $questionIds)->get();
                $order = 1;
                $syncData = [];
                foreach ($validQuestions as $q) {
                    $syncData[$q->id] = [
                        'display_order' => $order++,
                        'score'         => $q->score ?? 1.0,
                    ];
                }
                $exam->questions()->sync($syncData);
            }

            $audit->updated('exams', 'Exam:updated', $exam, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'آزمون با موفقیت ویرایش شد.',
            'data'    => $exam->fresh(['subject', 'creator', 'sections', 'questions']),
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
     * بررسی وابستگی‌ها برای جلوگیری از حذف تصادفی داده‌های تاریخی داوطلبان و شفت‌ها.
     */
    public function destroy(Exam $exam, AuditLogService $audit)
    {
        Gate::authorize('delete', $exam);

        // جلوگیری از حذف در صورت وجود اطلاعات تاریخی
        if ($exam->shifts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'امکان حذف این آزمون وجود ندارد زیرا برای آن شفت‌های اجرایی ثبت شده است.',
            ], 422);
        }

        if ($exam->snapshots()->whereHas('attempts')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'امکان حذف این آزمون وجود ندارد زیرا دارای سوابق پاسخ‌دهی داوطلبان است.',
            ], 422);
        }

        // snapshot قبل از حذف + ثبت audit در همان تراکنش.
        DB::transaction(function () use ($exam, $audit) {
            $before = $audit->snapshot($exam);

            // حذف پیوندهای سوالات آزمون
            $exam->questions()->detach();

            $exam->delete();

            $audit->deleted('exams', 'Exam:deleted', $exam, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'آزمون با موفقیت حذف شد.',
        ]);
    }

    /**
     * دریافت لیست سوالات اختصاصی متصل‌شده به این آزمون.
     */
    public function getQuestions(Exam $exam): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('view', $exam);

        $questions = $exam->questions()
            ->with(['options', 'passage', 'media'])
            ->get();

        $totalScore = (float) $questions->sum(function ($q) {
            return $q->pivot->score ?? $q->score ?? 1.0;
        });

        return response()->json([
            'success'     => true,
            'data'        => $questions,
            'count'       => $questions->count(),
            'total_score' => $totalScore,
        ]);
    }

    /**
     * دریافت سوالات تاییدشده و مجاز بانک سوالات مربوط به مضمون این آزمون.
     */
    public function getAvailableQuestions(Request $request, Exam $exam): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('view', $exam);

        $currentQuestionIds = $exam->questions()->pluck('questions.id')->toArray();

        $query = \App\Models\Question::with(['options', 'passage', 'media'])
            ->where('subject_id', $exam->subject_id)
            ->whereIn('status', ['approved', 'active'])
            ->when($request->search, fn($q) => $q->where('question_text', 'like', '%'.$request->search.'%'))
            ->when($request->section_name, fn($q) => $q->where('section_name', $request->section_name))
            ->when($request->question_type, fn($q) => $q->where('question_type', $request->question_type))
            ->when($request->difficulty, fn($q) => $q->where('difficulty', $request->difficulty))
            ->when($request->topic, fn($q) => $q->where('topic', 'like', '%'.$request->topic.'%'))
            ->orderByDesc('created_at');

        $questions = $query->paginate($request->per_page ?? 20);

        // افزودن نشانه اینکه آیا سوال هم‌اکنون در آزمون انتخاب شده یا خیر
        $questions->getCollection()->transform(function ($q) use ($currentQuestionIds) {
            $q->is_selected = in_array($q->id, $currentQuestionIds);
            return $q;
        });

        return response()->json([
            'success' => true,
            'data'    => $questions,
        ]);
    }

    /**
     * اتصال و همگام‌سازی سوالات آزمون با اعتبارسنجی دقیق.
     * فقط سوالات تاییدشده (approved/active) و متعلق به مضمون آزمون مجاز هستند.
     */
    public function syncQuestions(Request $request, Exam $exam, AuditLogService $audit): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('update', $exam);

        if (in_array($exam->status, ['running', 'completed', 'archived'])) {
            return response()->json([
                'success' => false,
                'message' => 'امکان تغییر سوالات آزمون در حال اجرا، تکمیل‌شده یا بایگانی‌شده وجود ندارد.',
            ], 422);
        }

        $validated = $request->validate([
            'question_ids'               => ['required', 'array'],
            'question_ids.*'             => ['required', 'integer', 'exists:questions,id'],
            'scores'                     => ['nullable', 'array'],
            'scores.*'                   => ['nullable', 'numeric', 'min:0'],
        ]);

        $questionIds = array_values(array_unique($validated['question_ids']));
        $scores = $validated['scores'] ?? [];

        // بررسی اینکه تمام سوالات متعلق به همین مضمون و در وضعیت تأییدشده باشند
        $validQuestions = \App\Models\Question::whereIn('id', $questionIds)
            ->where('subject_id', $exam->subject_id)
            ->whereIn('status', ['approved', 'active'])
            ->get();

        if ($validQuestions->count() !== count($questionIds)) {
            return response()->json([
                'success' => false,
                'message' => 'برخی از سوالات انتخاب‌شده یا تایید نشده‌اند، یا متعلق به مضمون این آزمون نیستند.',
            ], 422);
        }

        $syncData = [];
        $order = 1;
        $totalCalculatedScore = 0.0;

        foreach ($questionIds as $qId) {
            $qModel = $validQuestions->firstWhere('id', $qId);
            $qScore = isset($scores[$qId]) ? (float) $scores[$qId] : (float) ($qModel->score ?? 1.0);
            $totalCalculatedScore += $qScore;

            $syncData[$qId] = [
                'display_order' => $order++,
                'score'         => $qScore,
            ];
        }

        DB::transaction(function () use ($exam, $syncData, $totalCalculatedScore, $audit) {
            $exam->questions()->sync($syncData);

            // به‌روزرسانی شمارنده سوالات در بخش عمومی در صورت لزوم
            if ($exam->sections()->count() === 0) {
                $exam->sections()->create([
                    'name'           => 'عمومی',
                    'question_count' => count($syncData),
                    'total_marks'    => $totalCalculatedScore,
                    'display_order'  => 1,
                ]);
            }
        });

        return response()->json([
            'success'     => true,
            'message'     => 'سوالات آزمون با موفقیت به‌روزرسانی و ذخیره شدند.',
            'count'       => count($syncData),
            'total_score' => $totalCalculatedScore,
            'data'        => $exam->fresh(['questions.options', 'sections']),
        ]);
    }

    /**
     * حذف یک سوال از آزمون.
     */
    public function removeQuestion(Exam $exam, \App\Models\Question $question, AuditLogService $audit): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('update', $exam);

        if (in_array($exam->status, ['running', 'completed', 'archived'])) {
            return response()->json([
                'success' => false,
                'message' => 'امکان تغییر سوالات آزمون در حال اجرا، تکمیل‌شده یا بایگانی‌شده وجود ندارد.',
            ], 422);
        }

        $exam->questions()->detach($question->id);

        return response()->json([
            'success' => true,
            'message' => 'سوال با موفقیت از این آزمون حذف گردید.',
            'count'   => $exam->questions()->count(),
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
