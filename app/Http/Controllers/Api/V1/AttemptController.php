<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attempt\SaveAnswerRequest;
use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\ExamSnapshot;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * کنترلر موتور برگزاری آزمون برای داوطلب (شروع، بازیابی، ذخیره پاسخ، علامت‌گذاری، ضربان قلب و ثبت نهایی).
 *
 * اصول امنیتی، منبع حقیقت واحد و هم‌زمانی (Step 14.6.5c):
 * 1. در متد start ترتیب قفل استاندارد:
 *    Shift (id ASC) -> CandidateAssignment (id ASC) -> Computer (id ASC) -> Attempt (id ASC)
 * 2. در متدهای submit و autoSubmit ترتیب قفل کوتاه‌تر و سازگار:
 *    Computer (id ASC) -> Attempt (id ASC)
 * 3. بازبینی اسنپ‌شات پس از قفل‌گذاری با حلقه تلاش مجدد بیرونی (حداکثر ۳ بار) برای مدیریت تغییرات هم‌زمان.
 * 4. حفظ کامل قابلیت Resume حتی پس از SessionTransfer (زمانی که attempts.computer_id با candidate_assignments.computer_id متفاوت است و کامپیوتر در وضعیت exam قرار دارد).
 */
class AttemptController extends Controller
{
    private const SNAPSHOT_CHANGED_MARKER = '__ATTEMPT_SNAPSHOT_CHANGED__';

    /**
     * لیست جلسات آزمون برای مانیتورینگ زنده ادمین و ناظران.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user('admin');
        if (!$user || !$user->hasPermission('sessions.view')) {
            return response()->json([
                'success' => false,
                'message' => 'شما مجوز مشاهده جلسات آزمون را ندارید.',
            ], 403);
        }

        $query = Attempt::with(['candidate:id,name,candidate_code', 'exam:id,title', 'computer:id,computer_code'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('exam_id'), fn($q) => $q->where('exam_id', $request->exam_id))
            ->latest('id');

        $attempts = $query->paginate(min(max((int) ($request->per_page ?? 25), 1), 100));

        return response()->json([
            'success' => true,
            'data'    => $attempts,
        ]);
    }

    /**
     * شروع آزمون جدید یا بازیابی آزمون ناتمام (Resume).
     */
    public function start(Request $request): JsonResponse
    {
        $candidate = auth('candidate')->user();
        $maxSnapshotRetries = 3;

        for ($try = 1; $try <= $maxSnapshotRetries; $try++) {
            // ۱. خواندن اسنپ‌شات اولیه خارج از تراکنش
            $snapshotAssignment = CandidateAssignment::with(['exam', 'computer', 'shift'])
                ->where('candidate_id', $candidate->id)
                ->whereHas('exam', fn ($q) => $q->whereIn('status', ['published', 'running']))
                ->latest()
                ->first();

            if (!$snapshotAssignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'هیچ آزمون فعالی برای شما یافت نشد.',
                ], 422);
            }

            $snapshotAssignmentId = (int) $snapshotAssignment->id;
            $snapshotExamId = (int) $snapshotAssignment->exam_id;
            $snapshotShiftId = $snapshotAssignment->shift_id ? (int) $snapshotAssignment->shift_id : null;
            $snapshotAssignedComputerId = $snapshotAssignment->computer_id ? (int) $snapshotAssignment->computer_id : null;

            $snapshotActiveAttempt = Attempt::query()
                ->where('candidate_id', $candidate->id)
                ->where('exam_id', $snapshotExamId)
                ->whereIn('status', ['in_progress', 'disconnected'])
                ->latest('id')
                ->first();

            $snapshotActiveAttemptId = $snapshotActiveAttempt ? (int) $snapshotActiveAttempt->id : null;
            $snapshotActiveAttemptComputerId = ($snapshotActiveAttempt && $snapshotActiveAttempt->computer_id)
                ? (int) $snapshotActiveAttempt->computer_id
                : null;

            $examSnapshot = ExamSnapshot::where('exam_id', $snapshotExamId)
                ->latest('created_at')
                ->first();

            try {
                $outcome = DB::transaction(function () use (
                    $request,
                    $candidate,
                    $snapshotAssignmentId,
                    $snapshotExamId,
                    $snapshotShiftId,
                    $snapshotAssignedComputerId,
                    $snapshotActiveAttemptId,
                    $snapshotActiveAttemptComputerId,
                    $examSnapshot
                ) {
                    // گام ۱ قفل: Shift (id ASC)
                    if ($snapshotShiftId !== null) {
                        Shift::whereKey($snapshotShiftId)
                            ->lockForUpdate()
                            ->first();
                    }

                    // گام ۲ قفل: CandidateAssignment (id ASC)
                    $lockedCandidateAssignments = CandidateAssignment::with('exam')
                        ->where(function ($q) use ($candidate, $snapshotAssignmentId) {
                            $q->where('candidate_id', $candidate->id)
                              ->orWhere('id', $snapshotAssignmentId);
                        })
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    $lockedAssignment = $lockedCandidateAssignments->firstWhere('id', $snapshotAssignmentId);

                    $latestPublishedAssignmentId = CandidateAssignment::where('candidate_id', $candidate->id)
                        ->whereHas('exam', fn ($q) => $q->whereIn('status', ['published', 'running']))
                        ->latest()
                        ->lockForUpdate()
                        ->value('id');

                    // گام ۳ قفل: Computer (id ASC) شامل کامپیوتر تخصیص‌یافته و کامپیوتر فعلی تلاش فعال
                    $computerIdsToLock = array_values(array_unique(array_filter(
                        [$snapshotAssignedComputerId, $snapshotActiveAttemptComputerId],
                        fn ($id) => $id !== null
                    )));
                    sort($computerIdsToLock);

                    $lockedComputers = empty($computerIdsToLock)
                        ? collect()
                        : Computer::whereIn('id', $computerIdsToLock)
                            ->orderBy('id', 'asc')
                            ->lockForUpdate()
                            ->get()
                            ->keyBy('id');

                    // گام ۴ قفل: Attempt (id ASC) مستقیماً از طریق Attempt::query()
                    $lockedAttempts = Attempt::query()
                        ->where(function ($q) use ($candidate, $snapshotExamId, $computerIdsToLock) {
                            $q->where(function ($qCandidate) use ($candidate, $snapshotExamId) {
                                $qCandidate->where('candidate_id', $candidate->id)
                                           ->where('exam_id', $snapshotExamId);
                            });
                            if (!empty($computerIdsToLock)) {
                                $q->orWhere(function ($qComputer) use ($computerIdsToLock) {
                                    $qComputer->whereIn('computer_id', $computerIdsToLock)
                                              ->whereIn('status', ['in_progress', 'disconnected']);
                                });
                            }
                        })
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    // گام ۵: بازبینی اسنپ‌شات پس از قفل‌گذاری و پیش از هرگونه نوشتن
                    if (
                        !$lockedAssignment
                        || $latestPublishedAssignmentId === null
                        || (int) $latestPublishedAssignmentId !== $snapshotAssignmentId
                        || !$lockedAssignment->exam
                        || !in_array($lockedAssignment->exam->status, ['published', 'running'], true)
                        || (int) $lockedAssignment->exam_id !== $snapshotExamId
                        || ($lockedAssignment->shift_id ? (int) $lockedAssignment->shift_id : null) !== $snapshotShiftId
                        || ($lockedAssignment->computer_id ? (int) $lockedAssignment->computer_id : null) !== $snapshotAssignedComputerId
                    ) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                    }

                    $lockedActiveAttempt = $lockedAttempts
                        ->where('candidate_id', $candidate->id)
                        ->where('exam_id', $snapshotExamId)
                        ->whereIn('status', ['in_progress', 'disconnected'])
                        ->sortByDesc('id')
                        ->first();

                    $currentActiveAttemptId = $lockedActiveAttempt ? (int) $lockedActiveAttempt->id : null;
                    $currentActiveAttemptComputerId = ($lockedActiveAttempt && $lockedActiveAttempt->computer_id)
                        ? (int) $lockedActiveAttempt->computer_id
                        : null;

                    if (
                        $currentActiveAttemptId !== $snapshotActiveAttemptId
                        || $currentActiveAttemptComputerId !== $snapshotActiveAttemptComputerId
                    ) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                    }

                    // گام ۶: مسیر بازیابی (Resume) برای تلاش فعال موجود داوطلب (حتی پس از SessionTransfer)
                    if ($lockedActiveAttempt !== null) {
                        return [
                            'error'     => false,
                            'is_resume' => true,
                            'attempt'   => $lockedActiveAttempt,
                        ];
                    }

                    // گام ۷: بررسی اینکه آیا داوطلب قبلاً آزمون را به پایان رسانده است
                    $completedAttempt = $lockedAttempts
                        ->where('candidate_id', $candidate->id)
                        ->where('exam_id', $snapshotExamId)
                        ->whereIn('status', ['completed', 'auto_submitted'])
                        ->first();

                    if ($completedAttempt !== null) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'شما قبلاً در این آزمون شرکت کرده‌اید و امکان ورود مجدد وجود ندارد.',
                        ];
                    }

                    // گام ۸: بررسی پیش‌شرط‌های کامپیوتر فقط برای شروع تلاش جدید
                    if ($snapshotAssignedComputerId === null) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'هیچ کامپیوتری به شما تخصیص داده نشده است. لطفاً به ناظر لابراتوار مراجعه کنید.',
                        ];
                    }

                    $lockedComputer = $lockedComputers->get($snapshotAssignedComputerId);
                    if (!$lockedComputer || $lockedComputer->status !== 'online') {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'کامپیوتر تخصیص‌یافته در وضعیت آماده (آنلاین) نیست. لطفاً به ناظر لابراتوار مراجعه کنید.',
                        ];
                    }

                    $conflictingAttemptOnComputer = $lockedAttempts->first(function (Attempt $a) use ($snapshotAssignedComputerId) {
                        return (int) $a->computer_id === $snapshotAssignedComputerId
                            && in_array($a->status, ['in_progress', 'disconnected'], true);
                    });

                    if ($conflictingAttemptOnComputer !== null) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'کامپیوتر تخصیص‌یافته در حال حاضر دارای جلسه آزمون فعال است.',
                        ];
                    }

                    if (!$examSnapshot) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'نسخه ثابت آزمون (Snapshot) یافت نشد. لطفاً به ناظر اطلاع دهید.',
                        ];
                    }

                    // گام ۹: ایجاد تلاش جدید و تغییر وضعیت کامپیوتر به exam تحت قفل مطابق اسکیمای واقعی attempts
                    $exam = $lockedAssignment->exam;
                    $now = now();

                    $attempt = Attempt::create([
                        'candidate_id'            => $candidate->id,
                        'exam_id'                 => $exam->id,
                        'candidate_assignment_id' => $lockedAssignment->id,
                        'exam_snapshot_id'        => $examSnapshot->id,
                        'computer_id'             => $lockedComputer->id,
                        'ip_address'              => $request->ip() ?? '127.0.0.1',
                        'browser'                 => $request->userAgent(),
                        'device'                  => $request->header('X-Device', 'unknown'),
                        'started_at'              => $now,
                        'expires_at'              => $now->copy()->addMinutes((int) $exam->duration_minutes),
                        'last_activity_at'        => $now,
                        'status'                  => 'in_progress',
                        'session_token'           => Str::random(64),
                        'connection_status'       => 'connected',
                        'last_heartbeat_at'       => $now,
                        'answered_count'          => 0,
                    ]);

                    // تغییر وضعیت کامپیوتر به در حال آزمون (exam)
                    $lockedComputer->update(['status' => 'exam']);

                    // تغییر وضعیت داوطلب
                    $candidate->update(['status' => 'in_progress']);

                    return [
                        'error'     => false,
                        'is_resume' => false,
                        'attempt'   => $attempt,
                    ];
                }, 3);

                if ($outcome['error']) {
                    return response()->json([
                        'success' => false,
                        'message' => $outcome['message'],
                    ], $outcome['status']);
                }

                return $this->buildAttemptResponse($outcome['attempt'], $outcome['is_resume']);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === self::SNAPSHOT_CHANGED_MARKER) {
                    continue;
                }
                throw $e;
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'به دلیل تغییرات هم‌زمان روی اطلاعات صندلی یا آزمون، شروع جلسه انجام نشد. لطفاً مجدداً تلاش کنید.',
        ], 409);
    }

    /**
     * دریافت وضعیت جاری جلسه آزمون داوطلب (State).
     * شامل اطلاعات زمان باقیمانده، سوالات، و پاسخ‌های ثبت‌شده داوطلب.
     */
    public function state(Request $request, Attempt $attempt): JsonResponse
    {
        $candidate = auth('candidate')->user();

        if ($attempt->candidate_id !== $candidate->id) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        if ($attempt->isExpired() && in_array($attempt->status, ['in_progress', 'disconnected'], true)) {
            $this->autoSubmit($attempt);
            return response()->json([
                'success' => false,
                'message' => 'زمان آزمون شما به پایان رسیده و آزمون به‌صورت خودکار ثبت شد.',
                'status'  => 'auto_submitted',
            ], 422);
        }

        return $this->buildAttemptResponse($attempt, true);
    }

    /**
     * دریافت سوالات آزمون از روی اسنپ‌شات (بدون ارسال کلید پاسخ صحیح به کلاینت).
     */
    public function questions(Request $request, Attempt $attempt): JsonResponse
    {
        $candidate = auth('candidate')->user();

        if ($attempt->candidate_id !== $candidate->id) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز به جلسه آزمون.'], 403);
        }

        if ($attempt->isTimeExpired() && in_array($attempt->status, ['in_progress', 'disconnected'])) {
            $this->autoSubmit($attempt);
            return response()->json([
                'success' => false,
                'message' => 'زمان آزمون شما به پایان رسیده و آزمون به‌صورت خودکار ثبت شده است.',
                'status'  => 'auto_submitted',
            ], 422);
        }

        $snapshotData = $attempt->examSnapshot->snapshot_data;
        $questionsMap = collect($snapshotData['questions'] ?? [])->keyBy('id');
        $questionOrder = $attempt->question_order ?? [];
        $optionOrder = $attempt->option_order ?? [];

        // دریافت پاسخ‌های قبلی داوطلب برای بازیابی
        $savedAnswers = $attempt->answers()->get()->keyBy('question_id');

        $orderedQuestions = [];
        foreach ($questionOrder as $index => $qId) {
            $q = $questionsMap->get($qId);
            if (!$q) {
                continue;
            }

            // مرتب‌سازی گزینه‌ها طبق ترتیب اختصاصی داوطلب و حذف فیلد is_correct
            $optionsMap = collect($q['options'] ?? [])->keyBy('id');
            $orderedOptions = [];
            $optIds = $optionOrder[$qId] ?? $optionsMap->keys()->toArray();

            foreach ($optIds as $optId) {
                $opt = $optionsMap->get($optId);
                if ($opt) {
                    $orderedOptions[] = [
                        'id'          => $opt['id'],
                        'option_text' => $opt['option_text'],
                    ];
                }
            }

            $saved = $savedAnswers->get($qId);

            $orderedQuestions[] = [
                'number'             => $index + 1,
                'id'                 => $q['id'],
                'section'            => $q['section'] ?? null,
                'question_text'      => $q['question_text'],
                'reading_passage'    => $q['reading_passage'] ?? null,
                'points'             => $q['points'] ?? 1,
                'options'            => $orderedOptions,
                'selected_option_id' => $saved?->selected_option_id,
                'is_flagged'         => (bool) ($saved?->is_flagged ?? false),
            ];
        }

        return response()->json([
            'success'           => true,
            'attempt_id'        => $attempt->id,
            'remaining_seconds' => $attempt->calculateRemainingSeconds(),
            'total_questions'   => count($orderedQuestions),
            'questions'         => $orderedQuestions,
        ]);
    }

    /**
     * ذخیره فوری پاسخ یک سوال (Auto-Save).
     */
    public function saveAnswer(SaveAnswerRequest $request, Attempt $attempt): JsonResponse
    {
        $candidate = auth('candidate')->user();

        if ($attempt->candidate_id !== $candidate->id) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        if (!in_array($attempt->status, ['in_progress', 'disconnected'])) {
            return response()->json([
                'success' => false,
                'message' => 'این آزمون قبلاً ثبت نهایی شده و امکان تغییر پاسخ وجود ندارد.',
            ], 422);
        }

        if ($attempt->isTimeExpired()) {
            $this->autoSubmit($attempt);
            return response()->json([
                'success' => false,
                'message' => 'زمان آزمون به پایان رسیده و پاسخ‌ها به‌صورت خودکار ثبت شدند.',
                'status'  => 'auto_submitted',
            ], 422);
        }

        // بررسی تعلق سوال و گزینه به اسنپ‌شات آزمون
        $questionId = (int) $request->input('question_id');
        $selectedOptionId = $request->input('selected_option_id');

        $questionsMap = collect($attempt->examSnapshot->snapshot_data['questions'] ?? [])->keyBy('id');
        $question = $questionsMap->get($questionId);

        if (!$question) {
            return response()->json([
                'success' => false,
                'message' => 'سوال مورد نظر متعلق به این آزمون نیست.',
            ], 422);
        }

        if ($selectedOptionId !== null) {
            $validOptionIds = collect($question['options'] ?? [])->pluck('id')->toArray();
            if (!in_array((int) $selectedOptionId, $validOptionIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'گزینه انتخاب‌شده متعلق به این سوال نیست.',
                ], 422);
            }
        }

        $answer = AttemptAnswer::updateOrCreate(
            [
                'attempt_id'  => $attempt->id,
                'question_id' => $questionId,
            ],
            [
                'selected_option_id' => $selectedOptionId,
                'answered_at'        => now(),
            ]
        );

        return response()->json([
            'success'           => true,
            'message'           => 'پاسخ با موفقیت ذخیره شد.',
            'question_id'       => $answer->question_id,
            'selected_option_id'=> $answer->selected_option_id,
            'remaining_seconds' => $attempt->calculateRemainingSeconds(),
        ]);
    }

    /**
     * علامت‌گذاری یا برداشتن علامت سوال برای مرور بعدی (Flag).
     */
    public function toggleFlag(Request $request, Attempt $attempt): JsonResponse
    {
        $candidate = auth('candidate')->user();

        if ($attempt->candidate_id !== $candidate->id) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        if (!in_array($attempt->status, ['in_progress', 'disconnected'])) {
            return response()->json(['success' => false, 'message' => 'آزمون فعال نیست.'], 422);
        }

        $request->validate([
            'question_id' => ['required', 'integer'],
            'is_flagged'  => ['required', 'boolean'],
        ]);

        $answer = AttemptAnswer::updateOrCreate(
            [
                'attempt_id'  => $attempt->id,
                'question_id' => (int) $request->input('question_id'),
            ],
            [
                'is_flagged' => $request->boolean('is_flagged'),
            ]
        );

        return response()->json([
            'success'     => true,
            'question_id' => $answer->question_id,
            'is_flagged'  => $answer->is_flagged,
        ]);
    }

    /**
     * ضربان قلب داوطلب و همگام‌سازی تایمر سرور (Heartbeat).
     * چرا تراکنش و قفل: جلوگیری از به‌روزرسانی ناقص Attempt و Computer در شرایط مسابقه.
     * چرا ترتیب قفل Computer سپس Attempt: یکسان با start و submit برای جلوگیری از بن‌بست.
     * چرا بازخوانی computer_id: جلوگیری از لمس کامپیوتر مبدأ پس از انتقال نشست.
     * چرا رد Attempt غیرفعال با 422: جلوگیری از گیر کردن Computer در وضعیت exam بدون Attempt فعال.
     */
    public function heartbeat(Request $request, Attempt $attempt): JsonResponse
    {
        // چرا: احراز هویت داوطلب از گارد candidate انجام می‌شود.
        $candidate = auth('candidate')->user();
        // چرا: جلوگیری از دسترسی داوطلب دیگر به این Attempt.
        if ($attempt->candidate_id !== $candidate->id) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }
        // چرا: مسیر اتمام زمان دست‌نخورده می‌ماند؛ فقط نام متد اصلاح شده است.
        if ($attempt->isExpired() && in_array($attempt->status, ['in_progress', 'disconnected'])) {
            $this->autoSubmit($attempt);
            return response()->json([
                'success' => true,
                'status' => 'auto_submitted',
                'remaining_seconds' => 0,
                'message' => 'زمان آزمون به پایان رسید و به‌صورت خودکار ثبت شد.',
            ]);
        }
        // چرا: حداکثر سه تلاش برای حالت تغییر همزمان computer_id مشابه start و submit.
        $maxSnapshotRetries = 3;
        $outcome = null;
        for ($try = 1; $try <= $maxSnapshotRetries; $try++) {
            try {
                // چرا: خوانش تازه بدون قفل برای تعیین ترتیب قفل Computer سپس Attempt.
                $snapshotComputerId = Attempt::whereKey($attempt->id)->value('computer_id');
                $snapshotComputerId = $snapshotComputerId !== null ? (int) $snapshotComputerId : null;
                $outcome = DB::transaction(function () use ($attempt, $candidate, $snapshotComputerId) {
                    // چرا گام ۱ قفل: Computer تا ترتیب قفل با start و submit یکسان بماند.
                    $lockedComputer = null;
                    if ($snapshotComputerId !== null) {
                        $lockedComputer = Computer::whereKey($snapshotComputerId)->lockForUpdate()->first();
                    }
                    // چرا گام ۲ قفل: Attempt تا وضعیت و computer_id پس از قفل بازخوانی شود.
                    $lockedAttempt = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                    // چرا: اگر computer_id همزمان عوض شد، تلاش مجدد با شناسه تازه.
                    $freshComputerId = $lockedAttempt->computer_id !== null ? (int) $lockedAttempt->computer_id : null;
                    if ($freshComputerId !== $snapshotComputerId) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                    }
                    // چرا: بررسی مجدد مالکیت پس از قفل.
                    if ((int) $lockedAttempt->candidate_id !== (int) $candidate->id) {
                        return ['guard' => 'forbidden'];
                    }
                    // چرا: Attempt غیرفعال نباید Computer را تغییر دهد.
                    if (! in_array($lockedAttempt->status, ['in_progress', 'disconnected'])) {
                        return ['guard' => 'inactive', 'status' => $lockedAttempt->status];
                    }
                    // چرا: ثبت ضربان و برگرداندن disconnected به in_progress.
                    $now = now();
                    $lockedAttempt->update([
                        'last_heartbeat_at' => $now,
                        'connection_status' => 'connected',
                        'status' => $lockedAttempt->status === 'disconnected' ? 'in_progress' : $lockedAttempt->status,
                    ]);
                    // چرا: فقط Computer فعلی همین Attempt به exam می‌رود.
                    if ($lockedComputer !== null && $freshComputerId !== null) {
                        $lockedComputer->update([
                            'last_seen_at' => $now,
                            'status' => 'exam',
                        ]);
                    }
                    // چرا: بازخوانی تازه برای پاسخ پس از update.
                    $lockedAttempt->refresh();
                    return [
                        'guard' => 'ok',
                        'status' => $lockedAttempt->status,
                        'remaining_seconds' => $lockedAttempt->remainingSeconds(),
                        'server_time' => $now->toIso8601String(),
                    ];
                });
                break;
            } catch (RuntimeException $e) {
                // چرا: فقط نشان تغییر همزمان باعث تلاش مجدد می‌شود.
                if ($e->getMessage() === self::SNAPSHOT_CHANGED_MARKER && $try < $maxSnapshotRetries) {
                    $attempt->refresh();
                    continue;
                }
                throw $e;
            }
        }
        // چرا: اگر تراکنش نتیجه‌ای نداشت، خطای سرور.
        if ($outcome === null) {
            throw new RuntimeException('heartbeat failed.');
        }
        // چرا: پاسخ 403 بدون هیچ تغییری در دیتابیس.
        if (($outcome['guard'] ?? null) === 'forbidden') {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }
        // چرا: Attempt غیرفعال با 422 رد می‌شود و Computer دست نمی‌خورد.
        if (($outcome['guard'] ?? null) === 'inactive') {
            return response()->json(['success' => false, 'message' => 'آزمون فعال نیست.'], 422);
        }
        // چرا: مسیر موفق همان ساختار قبلی را برمی‌گرداند.
        return response()->json([
            'success' => true,
            'status' => $outcome['status'],
            'remaining_seconds' => $outcome['remaining_seconds'],
            'server_time' => $outcome['server_time'],
        ]);
    }

    /**
     * ثبت نهایی دستی آزمون توسط داوطلب با رعایت ترتیب قفل Computer (id ASC) -> Attempt (id ASC).
     */
    public function submit(Request $request, Attempt $attempt): JsonResponse
    {
        $candidate = auth('candidate')->user();

        if ($attempt->candidate_id !== $candidate->id) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        $maxSnapshotRetries = 3;

        for ($try = 1; $try <= $maxSnapshotRetries; $try++) {
            $snapshotAttempt = Attempt::query()->findOrFail($attempt->id);
            $snapshotComputerId = $snapshotAttempt->computer_id ? (int) $snapshotAttempt->computer_id : null;

            if (!in_array($snapshotAttempt->status, ['in_progress', 'disconnected'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'این آزمون قبلاً ثبت نهایی شده است.',
                ], 422);
            }

            try {
                $outcome = DB::transaction(function () use ($attempt, $snapshotComputerId) {
                    // ۱. قفل کامپیوتر (Computer id ASC)
                    $lockedComputer = $snapshotComputerId !== null
                        ? Computer::whereKey($snapshotComputerId)->lockForUpdate()->first()
                        : null;

                    // ۲. قفل تلاش آزمون (Attempt id ASC)
                    $lockedAttempt = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();

                    // ۳. بازبینی اسنپ‌شات کامپیوتر پس از قفل‌گذاری
                    $currentComputerId = $lockedAttempt->computer_id ? (int) $lockedAttempt->computer_id : null;
                    if ($currentComputerId !== $snapshotComputerId) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                    }

                    if (!in_array($lockedAttempt->status, ['in_progress', 'disconnected'], true)) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'این آزمون قبلاً ثبت نهایی شده است.',
                        ];
                    }

                    $now = now();

                    if ($lockedAttempt->isTimeExpired()) {
                        $lockedAttempt->update([
                            'status'       => 'auto_submitted',
                            'submitted_at' => $now,
                        ]);

                        $lockedAttempt->candidate?->update(['status' => 'completed']);
                        $this->releaseComputerIfIdle($lockedComputer, $lockedAttempt->id);

                        return [
                            'error'          => false,
                            'auto_submitted' => true,
                            'submitted_at'   => $now->toIso8601String(),
                        ];
                    }

                    $lockedAttempt->update([
                        'status'       => 'completed',
                        'submitted_at' => $now,
                    ]);

                    $lockedAttempt->candidate?->update(['status' => 'completed']);
                    $this->releaseComputerIfIdle($lockedComputer, $lockedAttempt->id);

                    return [
                        'error'          => false,
                        'auto_submitted' => false,
                        'submitted_at'   => $lockedAttempt->fresh()->submitted_at?->toIso8601String(),
                    ];
                }, 3);

                if ($outcome['error']) {
                    return response()->json([
                        'success' => false,
                        'message' => $outcome['message'],
                    ], $outcome['status']);
                }

                if ($outcome['auto_submitted']) {
                    return response()->json([
                        'success' => true,
                        'message' => 'زمان آزمون به پایان رسیده و پاسخ‌های شما به‌صورت خودکار ثبت شد.',
                        'status'  => 'auto_submitted',
                    ]);
                }

                return response()->json([
                    'success'      => true,
                    'message'      => 'آزمون شما با موفقیت ثبت نهایی شد.',
                    'status'       => 'completed',
                    'submitted_at' => $outcome['submitted_at'],
                ]);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === self::SNAPSHOT_CHANGED_MARKER) {
                    continue;
                }
                throw $e;
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'به دلیل تغییرات هم‌زمان روی جلسه آزمون، ثبت نهایی انجام نشد. لطفاً مجدداً تلاش کنید.',
        ], 409);
    }

    /**
     * ثبت خودکار آزمون در صورت اتمام زمان سرور با رعایت ترتیب قفل Computer (id ASC) -> Attempt (id ASC).
     */
    private function autoSubmit(Attempt $attempt): void
    {
        $maxSnapshotRetries = 3;

        for ($try = 1; $try <= $maxSnapshotRetries; $try++) {
            $snapshotAttempt = Attempt::query()->find($attempt->id);
            if (!$snapshotAttempt || !in_array($snapshotAttempt->status, ['in_progress', 'disconnected'], true)) {
                return;
            }

            $snapshotComputerId = $snapshotAttempt->computer_id ? (int) $snapshotAttempt->computer_id : null;

            try {
                DB::transaction(function () use ($attempt, $snapshotComputerId) {
                    // ۱. قفل کامپیوتر (Computer id ASC)
                    $lockedComputer = $snapshotComputerId !== null
                        ? Computer::whereKey($snapshotComputerId)->lockForUpdate()->first()
                        : null;

                    // ۲. قفل تلاش آزمون (Attempt id ASC)
                    $lockedAttempt = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();

                    // ۳. بازبینی اسنپ‌شات کامپیوتر پس از قفل‌گذاری
                    $currentComputerId = $lockedAttempt->computer_id ? (int) $lockedAttempt->computer_id : null;
                    if ($currentComputerId !== $snapshotComputerId) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                    }

                    if (!in_array($lockedAttempt->status, ['in_progress', 'disconnected'], true)) {
                        return;
                    }

                    $lockedAttempt->update([
                        'status'       => 'auto_submitted',
                        'submitted_at' => now(),
                    ]);

                    $lockedAttempt->candidate?->update(['status' => 'completed']);
                    $this->releaseComputerIfIdle($lockedComputer, $lockedAttempt->id);
                }, 3);

                $attempt->refresh();
                return;
            } catch (RuntimeException $e) {
                if ($e->getMessage() === self::SNAPSHOT_CHANGED_MARKER) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * آزادسازی وضعیت کامپیوتر به online در صورتی که تلاش فعال دیگری روی آن وجود نداشته باشد.
     */
    private function releaseComputerIfIdle(?Computer $lockedComputer, int $finishedAttemptId): void
    {
        if (!$lockedComputer) {
            return;
        }

        $hasOtherActiveAttempt = Attempt::where('computer_id', $lockedComputer->id)
            ->where('id', '!=', $finishedAttemptId)
            ->whereIn('status', ['in_progress', 'disconnected'])
            ->exists();

        if (!$hasOtherActiveAttempt) {
            $lockedComputer->update(['status' => 'online']);
        }
    }

    /**
     * ساخت خروجی استاندارد برای شروع یا بازیابی آزمون.
     */
    private function buildAttemptResponse(Attempt $attempt, bool $isResume): JsonResponse
    {
        $questions = $attempt->examSnapshot
            ? $attempt->examSnapshot
                ->snapshotQuestions()
                ->select([
                    'id',
                    'exam_snapshot_id',
                    'exam_section_id',
                    'display_order',
                    'score',
                    'question_text_snapshot',
                    'question_type_snapshot',
                    'options_snapshot',
                    'passage_snapshot',
                    'media_snapshot',
                ])
                ->orderBy('display_order')
                ->get()
            : collect();

        // پالایش امنیتی: حذف کامل کلیدهای پاسخ صحیح (is_correct) از گزینه‌های سوال
        $sanitizedQuestions = $questions->map(function ($q) {
            $options = $q->options_snapshot ?? [];
            if (is_array($options)) {
                $sanitized = array_map(function ($opt) {
                    if (is_array($opt)) {
                        unset($opt['is_correct'], $opt['isCorrect']);
                    } elseif (is_object($opt)) {
                        unset($opt->is_correct, $opt->isCorrect);
                    }
                    return $opt;
                }, $options);
                $q->options_snapshot = $sanitized;
            }
            return $q;
        });

        $answers = $attempt->answers()
            ->select([
                'exam_snapshot_question_id',
                'selected_option_ids',
                'answer_text',
                'time_spent_seconds',
                'sync_status',
            ])
            ->get()
            ->keyBy('exam_snapshot_question_id');

        $remainingSeconds = method_exists($attempt, 'remainingSeconds')
            ? $attempt->remainingSeconds()
            : ($attempt->expires_at ? max(0, (int) now()->diffInSeconds($attempt->expires_at, false)) : 0);

        return response()->json([
            'success'   => true,
            'is_resume' => $isResume,
            'message'   => $isResume
                ? 'جلسه آزمون قبلی شما با موفقیت بازیابی شد.'
                : 'آزمون با موفقیت آغاز شد.',
            'data'      => [
                'attempt_id'  => $attempt->id,
                'computer_id' => $attempt->computer_id,
                'status'      => $attempt->status,
                'is_resume'   => $isResume,
                'attempt'     => [
                    'id'                => $attempt->id,
                    'exam_id'           => $attempt->exam_id,
                    'computer_id'       => $attempt->computer_id,
                    'status'            => $attempt->status,
                    'started_at'        => $attempt->started_at,
                    'expires_at'        => $attempt->expires_at,
                    'remaining_seconds' => $remainingSeconds,
                    'answered_count'    => $attempt->answered_count,
                    'session_token'     => $attempt->session_token,
                    'is_resume'         => $isResume,
                ],
                'questions'   => $sanitizedQuestions,
                'answers'     => $answers,
            ],
        ]);
    }
}