<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * مدیریت بانک سوالات — ایجاد، ویرایش، تأیید، حذف.
 * هر ویرایش یک نسخه جدید می‌سازد (Versioning).
 * گزینه‌ها بدون محدودیت تعداد هستند (بند 60.1 SRS).
 */
class QuestionController extends Controller
{
    /**
     * لیست سوالات با فیلتر و صفحه‌بندی.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Question::class);

        $query = Question::with(['subject', 'createdBy', 'reviewedBy', 'options', 'passage', 'media'])
            ->when($request->subject_id,   fn($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->section_name, fn($q) => $q->where('section_name', $request->section_name))
            ->when($request->status,       fn($q) => $q->where('status', $request->status))
            ->when($request->difficulty,   fn($q) => $q->where('difficulty', $request->difficulty))
            ->when($request->question_type,fn($q) => $q->where('question_type', $request->question_type))
            ->when($request->topic,        fn($q) => $q->where('topic', 'like', '%'.$request->topic.'%'))
            ->when($request->created_by,   fn($q) => $q->where('created_by', $request->created_by))
            ->when($request->search,       fn($q) => $q->where('question_text', 'like', '%'.$request->search.'%'))
            ->orderByDesc('created_at');

        $questions = $query->paginate($request->per_page ?? 15);

        // آمار شمارش سوالات بر اساس وضعیت برای تب‌های Approval
        $counts = [
            'draft'        => Question::where('status', 'draft')->count(),
            'under_review' => Question::where('status', 'under_review')->count(),
            'approved'     => Question::where('status', 'approved')->count(),
            'active'       => Question::where('status', 'active')->count(),
            'inactive'     => Question::where('status', 'inactive')->count(),
            'archived'     => Question::where('status', 'archived')->count(),
        ];

        return response()->json([
            'success' => true,
            'data'    => $questions,
            'counts'  => $counts,
        ]);
    }

    /**
     * نمایش یک سوال با جزئیات کامل.
     */
    public function show(Question $question)
    {
        Gate::authorize('view', $question);

        $question->load([
            'subject',
            'createdBy',
            'reviewedBy',
            'options',
            'passage',
            'media',
            'currentVersion',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $question,
        ]);
    }

    /**
     * ایجاد سوال جدید با گزینه‌ها — همه در یک Transaction.
     * وضعیت اولیه همیشه draft است.
     */
    public function store(Request $request, AuditLogService $audit)
    {
        Gate::authorize('create', Question::class);

        if ($request->input('question_type') === 'single_choice') {
            $request->merge(['question_type' => 'single']);
        } elseif ($request->input('question_type') === 'multiple_choice') {
            $request->merge(['question_type' => 'multi']);
        }
        if (!$request->has('difficulty') && $request->has('difficulty_level')) {
            $request->merge(['difficulty' => $request->input('difficulty_level')]);
        }

        $validated = $request->validate([
            'subject_id'    => ['required', 'exists:subjects,id'],
            'section_name'  => ['required', 'string', 'max:100'],
            'question_type' => ['required', Rule::in(Question::TYPES)],
            'question_text' => ['required', 'string'],
            'difficulty'    => ['required', Rule::in(['easy', 'medium', 'hard'])],
            'topic'         => ['nullable', 'string', 'max:100'],
            'score'         => ['required', 'numeric', 'min:0'],
            'passage_id'    => ['nullable', 'exists:passages,id'],
            'media_id'      => ['nullable', 'exists:media,id'],
            'options'       => [
                Rule::requiredIf(fn() => $request->input('question_type') !== 'short_answer'),
                'array',
                Rule::when(
                    $request->input('question_type') !== 'short_answer' && $request->input('question_type') !== 'true_false',
                    ['min:2']
                ),
                Rule::when(
                    $request->input('question_type') === 'true_false',
                    ['size:2']
                ),
            ],
            'options.*.option_text'   => ['required', 'string'],
            'options.*.is_correct'    => ['required', 'boolean'],
            'options.*.display_order' => ['nullable', 'integer'],
        ]);

        $options = $validated['options'] ?? [];
        $type = $validated['question_type'];

        if ($type === 'single' || $type === 'true_false') {
            $correctCount = collect($options)->filter(fn($opt) => !empty($opt['is_correct']))->count();
            if ($correctCount !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'برای این نوع سوال باید دقیقاً یک گزینه به عنوان پاسخ صحیح انتخاب شود.',
                    'errors'  => [
                        'options' => ['برای این نوع سوال باید دقیقاً یک گزینه به عنوان پاسخ صحیح انتخاب شود.'],
                    ],
                ], 422);
            }
        } elseif ($type === 'multi') {
            $correctCount = collect($options)->filter(fn($opt) => !empty($opt['is_correct']))->count();
            if ($correctCount < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'حداقل یک گزینه را به عنوان پاسخ صحیح انتخاب کنید.',
                    'errors'  => [
                        'options' => ['حداقل یک گزینه را به عنوان پاسخ صحیح انتخاب کنید.'],
                    ],
                ], 422);
            }
        }

        DB::transaction(function () use ($validated, &$question, $audit) {
            // ساخت سوال
            $question = Question::create([
                'subject_id'    => $validated['subject_id'],
                'section_name'  => $validated['section_name'],
                'question_type' => $validated['question_type'],
                'question_text' => $validated['question_text'],
                'difficulty'    => $validated['difficulty'],
                'topic'         => $validated['topic'] ?? null,
                'score'         => $validated['score'],
                'passage_id'    => $validated['passage_id'] ?? null,
                'media_id'      => $validated['media_id'] ?? null,
                'status'        => 'draft',
                'created_by'    => Auth::id(),
            ]);

            // ساخت گزینه‌ها
            if (!empty($validated['options'])) {
                foreach ($validated['options'] as $index => $opt) {
                    QuestionOption::create([
                        'question_id'   => $question->id,
                        'option_text'   => $opt['option_text'],
                        'is_correct'    => $opt['is_correct'],
                        'display_order' => $opt['display_order'] ?? $index,
                    ]);
                }
            }

            // گرفتن متن درک مطلب برای ثبت در اسنپ‌شات نسخه
            $passageSnapshot = null;
            if (!empty($validated['passage_id'])) {
                $passage = \App\Models\Passage::find($validated['passage_id']);
                $passageSnapshot = $passage?->body_text;
            }

            // ساخت اولین Version
            $version = QuestionVersion::create([
                'question_id'     => $question->id,
                'version_number'  => 1,
                'question_text'   => $question->question_text,
                'question_type'   => $question->question_type,
                'section_name'    => $question->section_name,
                'difficulty'      => $question->difficulty,
                'options_snapshot'=> $validated['options'] ?? [],
                'passage_snapshot'=> $passageSnapshot,
                'media_id'        => $question->media_id,
                'changed_by'      => Auth::id(),
                'change_reason'   => 'Initial version',
            ]);

            // اتصال current_version_id
            $question->update(['current_version_id' => $version->id]);

            // audit ایجاد سوال در همان تراکنش؛ فقط متادیتا (نه متن سوال و نه گزینه‌ها).
            $audit->record(
                'questions',
                'Question:created',
                'Question',
                $question->id,
                null,
                [
                    'subject_id' => $question->subject_id,
                    'status' => $question->status,
                    'question_type' => $question->question_type,
                    'section_name' => $question->section_name,
                    'difficulty' => $question->difficulty,
                    'version' => 1,
                    'options_count' => count($validated['options'] ?? []),
                ]
            );
        });

        $question->load(['subject', 'createdBy', 'options', 'passage', 'media', 'currentVersion']);

        return response()->json([
            'success' => true,
            'message' => 'سوال با موفقیت ایجاد شد.',
            'data'    => $question,
        ], 201);
    }

    /**
     * ویرایش سوال — یک Version جدید می‌سازد.
     * فقط سوالات draft و under_review قابل ویرایش هستند.
     */
    public function update(Request $request, Question $question, AuditLogService $audit)
    {
        Gate::authorize('update', $question);

        if ($request->input('question_type') === 'single_choice') {
            $request->merge(['question_type' => 'single']);
        } elseif ($request->input('question_type') === 'multiple_choice') {
            $request->merge(['question_type' => 'multi']);
        }
        if (!$request->has('difficulty') && $request->has('difficulty_level')) {
            $request->merge(['difficulty' => $request->input('difficulty_level')]);
        }

        $targetType = $request->input('question_type', $question->question_type);

        $validated = $request->validate([
            'section_name'  => ['sometimes', 'string', 'max:100'],
            'question_type' => ['sometimes', Rule::in(Question::TYPES)],
            'question_text' => ['sometimes', 'string'],
            'difficulty'    => ['sometimes', Rule::in(['easy', 'medium', 'hard'])],
            'topic'         => ['nullable', 'string', 'max:100'],
            'score'         => ['sometimes', 'numeric', 'min:0'],
            'passage_id'    => ['nullable', 'exists:passages,id'],
            'media_id'      => ['nullable', 'exists:media,id'],
            'change_reason' => ['nullable', 'string', 'max:255'],
            'options'       => [
                'sometimes',
                'array',
                Rule::when(
                    $targetType !== 'short_answer' && $targetType !== 'true_false',
                    ['min:2']
                ),
                Rule::when(
                    $targetType === 'true_false',
                    ['size:2']
                ),
            ],
            'options.*.option_text'   => ['required', 'string'],
            'options.*.is_correct'    => ['required', 'boolean'],
            'options.*.display_order' => ['nullable', 'integer'],
        ]);

        if (isset($validated['options'])) {
            $options = $validated['options'];
            if ($targetType === 'single' || $targetType === 'true_false') {
                $correctCount = collect($options)->filter(fn($opt) => !empty($opt['is_correct']))->count();
                if ($correctCount !== 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'برای این نوع سوال باید دقیقاً یک گزینه به عنوان پاسخ صحیح انتخاب شود.',
                        'errors'  => [
                            'options' => ['برای این نوع سوال باید دقیقاً یک گزینه به عنوان پاسخ صحیح انتخاب شود.'],
                        ],
                    ], 422);
                }
            } elseif ($targetType === 'multi') {
                $correctCount = collect($options)->filter(fn($opt) => !empty($opt['is_correct']))->count();
                if ($correctCount < 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'حداقل یک گزینه را به عنوان پاسخ صحیح انتخاب کنید.',
                        'errors'  => [
                            'options' => ['حداقل یک گزینه را به عنوان پاسخ صحیح انتخاب کنید.'],
                        ],
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($validated, $question, $audit) {
            // متادیتای قبل از ویرایش برای old_value (متن سوال و گزینه‌ها ذخیره نمی‌شود).
            $oldMeta = [
                'status' => $question->status,
                'question_type' => $question->question_type,
                'section_name' => $question->section_name,
                'difficulty' => $question->difficulty,
                'score' => (string) $question->score,
                'options_count' => $question->options()->count(),
            ];
            $question->update(collect($validated)->except(['options', 'change_reason'])->toArray());

            // آپدیت گزینه‌ها — حذف قدیمی و ساخت جدید
            if (isset($validated['options'])) {
                $question->options()->delete();
                foreach ($validated['options'] as $index => $opt) {
                    QuestionOption::create([
                        'question_id'   => $question->id,
                        'option_text'   => $opt['option_text'],
                        'is_correct'    => $opt['is_correct'],
                        'display_order' => $opt['display_order'] ?? $index,
                    ]);
                }
            }

            // ساخت Version جدید
            $lastVersion = $question->versions()->first();
            
            $newVersionNumber = $lastVersion ? $lastVersion->version_number + 1 : 1;

            $passageId = $validated['passage_id'] ?? $question->passage_id;
            $passageSnapshot = null;
            if ($passageId) {
                $passage = \App\Models\Passage::find($passageId);
                $passageSnapshot = $passage?->body_text;
            }

            $version = QuestionVersion::create([
                'question_id'     => $question->id,
                'version_number'  => $newVersionNumber,
                'question_text'   => $question->question_text,
                'question_type'   => $question->question_type,
                'section_name'    => $question->section_name,
                'difficulty'      => $question->difficulty,
                'options_snapshot'=> $validated['options'] ?? $question->options->toArray(),
                'passage_snapshot'=> $passageSnapshot,
                'media_id'        => $question->media_id,
                'changed_by'      => Auth::id(),
                'change_reason'   => $validated['change_reason'] ?? null,
            ]);

            $question->update(['current_version_id' => $version->id]);

            // فقط یک audit برای کل عملیات ویرایش (سوال + گزینه‌ها + نسخه)؛ فقط نام فیلدها و متادیتا.
            $audit->record(
                'questions',
                'Question:updated',
                'Question',
                $question->id,
                $oldMeta,
                [
                    'status' => $question->status,
                    'question_type' => $question->question_type,
                    'section_name' => $question->section_name,
                    'difficulty' => $question->difficulty,
                    'score' => (string) $question->score,
                    'options_count' => $question->options()->count(),
                    'version' => $newVersionNumber,
                ],
                array_values(array_diff(array_keys($validated), ['change_reason']))
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'سوال با موفقیت ویرایش شد.',
            'data'    => $question->fresh(['subject', 'createdBy', 'options', 'currentVersion']),
        ]);
    }

    /**
     * تأیید گروهی سوالات در انتظار بررسی (Approve All).
     * فقط سوالات واجد شرایط و دارای مجوز در وضعیت under_review تأیید می‌شوند.
     */
    public function approveAll(Request $request, AuditLogService $audit): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user() ?: auth('admin')->user();
        if (!$user || !in_array($user->role?->name, ['Super Admin', 'Exam Administrator', 'Reviewer'])) {
            return response()->json([
                'success' => false,
                'message' => 'شما مجوز تأیید سوالات را ندارید.',
            ], 403);
        }

        $query = Question::where('status', 'under_review')
            ->when($request->subject_id, fn($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->section_name, fn($q) => $q->where('section_name', $request->section_name))
            ->when($request->question_ids, fn($q) => $q->whereIn('id', (array) $request->question_ids));

        $questions = $query->get();

        $approvedCount = 0;
        $skippedCount = 0;
        $reviewerId = $user->id;

        DB::transaction(function () use ($questions, $reviewerId, $audit, &$approvedCount, &$skippedCount) {
            $hasReviewedBy = Schema::hasColumn('questions', 'reviewed_by');
            $hasReviewedAt = Schema::hasColumn('questions', 'reviewed_at');
            $hasRejectionReason = Schema::hasColumn('questions', 'rejection_reason');

            foreach ($questions as $question) {
                if (Gate::allows('changeStatus', $question)) {
                    $previousStatus = (string) $question->status;
                    $updatePayload = [
                        'status' => 'approved',
                    ];
                    if ($hasReviewedBy) {
                        $updatePayload['reviewed_by'] = $reviewerId;
                    }
                    if ($hasReviewedAt) {
                        $updatePayload['reviewed_at'] = now();
                    }
                    if ($hasRejectionReason) {
                        $updatePayload['rejection_reason'] = null;
                    }

                    $question->update($updatePayload);

                    try {
                        $audit->statusChanged(
                            'questions',
                            'Question:status_changed',
                            $question,
                            $previousStatus,
                            'approved',
                            ['reviewed_by' => $reviewerId, 'bulk' => true]
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('AuditLog approveAll failed: ' . $e->getMessage(), [
                            'question_id' => $question->id,
                            'exception'   => $e,
                        ]);
                    }

                    $approvedCount++;
                } else {
                    $skippedCount++;
                }
            }
        });

        $message = $approvedCount > 0
            ? "تعداد {$approvedCount} سوال با موفقیت تأیید شد."
            : 'هیچ سوال واجد شرایطی برای تأیید یافت نشد.';

        if ($skippedCount > 0) {
            $message .= " ({$skippedCount} سوال بدون تغییر باقی ماند).";
        }

        return response()->json([
            'success'          => true,
            'message'          => $message,
            'approved_count'   => $approvedCount,
            'skipped_count'    => $skippedCount,
            'total_processed'  => $questions->count(),
        ]);
    }

    /**
     * تغییر وضعیت سوال — Approval Workflow.
     * Draft → Under Review → Approved → Active
     */
    public function changeStatus(Request $request, Question $question, AuditLogService $audit)
    {
        try {
            Gate::authorize('changeStatus', $question);

            $request->validate([
                'status' => ['required', Rule::in(Question::STATUSES)],
                'rejection_reason' => [
                    'nullable',
                    'string',
                    'max:1000',
                ],
            ]);

            $newStatus = $request->status;
            $oldStatus = $question->status;

            // انتقال‌های مجاز طبق SRS
            $allowed = [
                'draft'        => ['under_review'],
                'under_review' => ['draft', 'approved'],
                'approved'     => ['active', 'inactive'],
                'active'       => ['inactive', 'archived'],
                'inactive'     => ['active', 'archived'],
                'archived'     => [],
            ];

            if (!in_array($newStatus, $allowed[$oldStatus] ?? [])) {
                return response()->json([
                    'success' => false,
                    'message' => "انتقال از «{$oldStatus}» به «{$newStatus}» مجاز نیست.",
                ], 422);
            }

            $updateData = ['status' => $newStatus];

            $hasReviewedBy = Schema::hasColumn('questions', 'reviewed_by');
            $hasReviewedAt = Schema::hasColumn('questions', 'reviewed_at');
            $hasRejectionReason = Schema::hasColumn('questions', 'rejection_reason');
            $currentUserId = Auth::id() ?: auth('admin')->id();

            // در صورت رد سوال (under_review -> draft)، ثبت الزامی دلیل رد و ثبت بازبین
            if ($oldStatus === 'under_review' && $newStatus === 'draft') {
                $request->validate([
                    'rejection_reason' => ['required', 'string', 'min:3', 'max:1000'],
                ], [
                    'rejection_reason.required' => 'ثبت دلیل رد سوال الزامی است.',
                    'rejection_reason.min'      => 'دلیل رد سوال باید حداقل ۳ حرف باشد.',
                ]);

                if ($hasReviewedBy) {
                    $updateData['reviewed_by'] = $currentUserId;
                }
                if ($hasReviewedAt) {
                    $updateData['reviewed_at'] = now();
                }
                if ($hasRejectionReason) {
                    $updateData['rejection_reason'] = $request->rejection_reason;
                }
            } elseif ($newStatus === 'approved') {
                // در صورت تأیید سوال (under_review -> approved)، ثبت بازبین و پاکسازی دلیل رد
                if ($hasReviewedBy) {
                    $updateData['reviewed_by'] = $currentUserId;
                }
                if ($hasReviewedAt) {
                    $updateData['reviewed_at'] = now();
                }
                if ($hasRejectionReason) {
                    $updateData['rejection_reason'] = null;
                }
            } elseif ($oldStatus === 'draft' && $newStatus === 'under_review') {
                // ارسال مجدد سوال برای بررسی — پاکسازی دلیل رد قبلی
                if ($hasRejectionReason) {
                    $updateData['rejection_reason'] = null;
                }
            }

            // وضعیت قبلی، تغییر وضعیت و ثبت audit در یک تراکنش.
            $previousStatus = (string) $question->status;

            DB::transaction(function () use ($question, $updateData, $audit, $previousStatus) {
                $question->update($updateData);

                try {
                    $audit->statusChanged(
                        'questions',
                        'Question:status_changed',
                        $question,
                        $previousStatus,
                        (string) $question->status,
                        array_diff_key($updateData, ['status' => true])
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('AuditLog statusChanged failed: ' . $e->getMessage(), [
                        'question_id' => $question->id,
                        'exception'   => $e,
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => "وضعیت سوال با موفقیت به «{$newStatus}» تغییر کرد.",
                'data'    => $question->fresh(['subject', 'createdBy', 'reviewedBy', 'options', 'passage', 'media']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'شما مجوز تغییر وضعیت این سوال را ندارید.',
            ], 403);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error changing question status: ' . $e->getMessage(), [
                'question_id' => $question->id,
                'trace'       => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در تغییر وضعیت سوال: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * حذف سوال — فقط draft قابل حذف است (Soft Delete).
     */
    public function destroy(Question $question, AuditLogService $audit)
    {
        Gate::authorize('delete', $question);

        if ($question->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'تنها سوالات با وضعیت پیش‌نویس (draft) قابل حذف هستند.',
            ], 422);
        }

        // متادیتای سوال قبل از حذف نرم + audit در همان تراکنش.
        DB::transaction(function () use ($question, $audit) {
            $meta = [
                'subject_id' => $question->subject_id,
                'status' => $question->status,
                'question_type' => $question->question_type,
                'section_name' => $question->section_name,
                'difficulty' => $question->difficulty,
            ];

            $question->delete();

            $audit->record('questions', 'Question:deleted', 'Question', $question->id, $meta, null);
        });

        return response()->json([
            'success' => true,
            'message' => 'سوال با موفقیت حذف شد.',
        ]);
    }

    /**
     * تاریخچه نسخه‌های یک سوال.
     */
    public function versions(Question $question)
    {
        Gate::authorize('view', $question);

        $versions = $question->versions()->with('changedBy')->get();

        return response()->json([
            'success' => true,
            'data'    => $versions,
        ]);
    }
}
