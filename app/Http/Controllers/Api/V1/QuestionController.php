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

        $query = Question::with(['subject', 'createdBy', 'options'])
            ->when($request->subject_id,   fn($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->section_name, fn($q) => $q->where('section_name', $request->section_name))
            ->when($request->status,       fn($q) => $q->where('status', $request->status))
            ->when($request->difficulty,   fn($q) => $q->where('difficulty', $request->difficulty))
            ->when($request->question_type,fn($q) => $q->where('question_type', $request->question_type))
            ->when($request->created_by,   fn($q) => $q->where('created_by', $request->created_by))
            ->when($request->search,       fn($q) => $q->where('question_text', 'like', '%'.$request->search.'%'))
            ->orderByDesc('created_at');

        $questions = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data'    => $questions,
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
            'options'       => ['required_unless:question_type,short_answer', 'array', 'min:2'],
            'options.*.option_text'   => ['required', 'string'],
            'options.*.is_correct'    => ['required', 'boolean'],
            'options.*.display_order' => ['integer'],
        ]);

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

            // ساخت اولین Version
            $version = QuestionVersion::create([
                'question_id'     => $question->id,
                'version_number'  => 1,
                'question_text'   => $question->question_text,
                'question_type'   => $question->question_type,
                'section_name'    => $question->section_name,
                'difficulty'      => $question->difficulty,
                'options_snapshot'=> $validated['options'] ?? [],
                'passage_snapshot'=> null,
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

        $question->load(['subject', 'createdBy', 'options', 'currentVersion']);

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
            'options'       => ['sometimes', 'array', 'min:2'],
            'options.*.option_text'   => ['required', 'string'],
            'options.*.is_correct'    => ['required', 'boolean'],
            'options.*.display_order' => ['integer'],
        ]);

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

            $version = QuestionVersion::create([
                'question_id'     => $question->id,
                'version_number'  => $newVersionNumber,
                'question_text'   => $question->question_text,
                'question_type'   => $question->question_type,
                'section_name'    => $question->section_name,
                'difficulty'      => $question->difficulty,
                'options_snapshot'=> $validated['options'] ?? $question->options->toArray(),
                'passage_snapshot'=> null,
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
     * تغییر وضعیت سوال — Approval Workflow.
     * Draft → Under Review → Approved → Active
     */
    public function changeStatus(Request $request, Question $question, AuditLogService $audit)
    {
        Gate::authorize('changeStatus', $question);

        $request->validate([
            'status' => ['required', Rule::in(Question::STATUSES)],
        ]);

        $newStatus = $request->status;
        $oldStatus = $question->status;

        // انتقال‌های مجاز
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

        // ثبت Reviewer در صورت تأیید
        if ($newStatus === 'approved') {
            $updateData['reviewed_by'] = Auth::id();
        }

        // وضعیت قبلی، تغییر وضعیت و ثبت audit در یک تراکنش.
        $previousStatus = (string) $question->status;

        DB::transaction(function () use ($question, $updateData, $audit, $previousStatus) {
            $question->update($updateData);

            $audit->statusChanged(
                'questions',
                'Question:status_changed',
                $question,
                $previousStatus,
                (string) $question->status,
                array_diff_key($updateData, ['status' => true])
            );
        });

        return response()->json([
            'success' => true,
            'message' => "وضعیت سوال به «{$newStatus}» تغییر کرد.",
            'data'    => $question->fresh(),
        ]);
    }

    /**
     * حذف سوال — فقط draft قابل حذف است (Soft Delete).
     */
    public function destroy(Question $question, AuditLogService $audit)
    {
        Gate::authorize('delete', $question);

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
