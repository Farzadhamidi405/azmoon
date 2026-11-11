<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SessionTransfer\StoreSessionTransferRequest;
use App\Models\Attempt;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\SessionTransfer;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * کنترلر انتقال جلسه آزمون داوطلب از یک کامپیوتر به کامپیوتر دیگر (توسط ناظر یا ادمین).
 *
 * اصول امنیتی و هم‌زمانی (Step 14.6.5c):
 * 1. رعایت ترتیب قفل یکنواخت:
 *    Shift (id ASC) -> CandidateAssignment (id ASC) -> Computer (id ASC) -> Attempt (id ASC)
 * 2. بازبینی اسنپ‌شات پس از قفل‌گذاری و حلقه تلاش مجدد بیرونی (حداکثر ۳ بار) در صورت تغییر هم‌زمان.
 * 3. بررسی عدم وجود جلسه آزمون فعال روی کامپیوتر مقصد و عدم رزرو بودن کامپیوتر مقصد برای داوطلب دیگر در همان شیفت.
 * 4. تغییر وضعیت کامپیوتر مبدأ به online و کامپیوتر مقصد به exam بدون دستکاری رکورد اولیه در candidate_assignments.
 */
class SessionTransferController extends Controller
{
    private const SNAPSHOT_CHANGED_MARKER = '__SESSION_TRANSFER_SNAPSHOT_CHANGED__';

    /**
     * لیست انتقال‌های انجام‌شده برای یک تلاش آزمون.
     */
    public function index(Request $request, Attempt $attempt): JsonResponse
    {
        Gate::authorize('viewAny', SessionTransfer::class);

        $transfers = $attempt->sessionTransfers()
            ->with([
                'fromComputer:id,computer_code,ip_address',
                'toComputer:id,computer_code,ip_address',
                'transferredByUser:id,full_name',
            ])
            ->orderBy('transferred_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $transfers,
        ]);
    }

    /**
     * انتقال جلسه فعال داوطلب به کامپیوتر جدید با رعایت سلسله‌مراتب قفل‌ها.
     */
    public function store(StoreSessionTransferRequest $request, Attempt $attempt): JsonResponse
    {
        Gate::authorize('create', SessionTransfer::class);

        $toComputerId = (int) $request->input('to_computer_id');
        $maxSnapshotRetries = 3;

        for ($try = 1; $try <= $maxSnapshotRetries; $try++) {
            // خواندن اسنپ‌شات تازه خارج از تراکنش
            $snapshotAttempt = Attempt::query()->findOrFail($attempt->id);

            if (!in_array($snapshotAttempt->status, ['in_progress', 'disconnected'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'فقط جلسات در حال برگزاری یا قطع‌شده قابل انتقال هستند.',
                ], 422);
            }

            $snapshotFromComputerId = $snapshotAttempt->computer_id ? (int) $snapshotAttempt->computer_id : null;

            if ($snapshotFromComputerId === $toComputerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'کامپیوتر مقصد نمی‌تواند همان کامپیوتر فعلی باشد.',
                ], 422);
            }

            // اطمینان از وجود کامپیوتر مقصد قبل از ورود به تراکنش
            Computer::query()->findOrFail($toComputerId);

            $snapshotAssignmentId = $snapshotAttempt->candidate_assignment_id
                ? (int) $snapshotAttempt->candidate_assignment_id
                : null;

            $snapshotAssignment = $snapshotAssignmentId
                ? CandidateAssignment::query()->find($snapshotAssignmentId)
                : CandidateAssignment::query()
                    ->where('candidate_id', $snapshotAttempt->candidate_id)
                    ->where('exam_id', $snapshotAttempt->exam_id)
                    ->latest('id')
                    ->first();

            $snapshotShiftId = $snapshotAssignment?->shift_id ? (int) $snapshotAssignment->shift_id : null;
            $snapshotResolvedAssignmentId = $snapshotAssignment ? (int) $snapshotAssignment->id : null;

            try {
                $outcome = DB::transaction(function () use (
                    $request,
                    $attempt,
                    $toComputerId,
                    $snapshotAttempt,
                    $snapshotFromComputerId,
                    $snapshotAssignmentId,
                    $snapshotResolvedAssignmentId,
                    $snapshotShiftId
                ) {
                    // ۱. قفل شیفت (Shift id ASC)
                    if ($snapshotShiftId !== null) {
                        Shift::whereKey($snapshotShiftId)
                            ->lockForUpdate()
                            ->first();
                    }

                    // ۲. قفل رکوردهای تخصیص در شیفت مربوطه (CandidateAssignment id ASC)
                    $lockedAssignments = collect();
                    if ($snapshotShiftId !== null || $snapshotResolvedAssignmentId !== null) {
                        $assignmentQuery = CandidateAssignment::query();
                        if ($snapshotShiftId !== null && $snapshotResolvedAssignmentId !== null) {
                            $assignmentQuery->where(function ($q) use ($snapshotShiftId, $snapshotResolvedAssignmentId) {
                                $q->where('shift_id', $snapshotShiftId)
                                  ->orWhere('id', $snapshotResolvedAssignmentId);
                            });
                        } elseif ($snapshotShiftId !== null) {
                            $assignmentQuery->where('shift_id', $snapshotShiftId);
                        } else {
                            $assignmentQuery->whereKey($snapshotResolvedAssignmentId);
                        }

                        $lockedAssignments = $assignmentQuery
                            ->orderBy('id', 'asc')
                            ->lockForUpdate()
                            ->get();
                    }

                    // ۳. قفل کامپیوترهای مبدأ و مقصد (Computer id ASC)
                    $computerIdsToLock = array_values(array_unique(array_filter(
                        [$snapshotFromComputerId, $toComputerId],
                        fn ($id) => $id !== null
                    )));
                    sort($computerIdsToLock);

                    $lockedComputers = Computer::whereIn('id', $computerIdsToLock)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    // ۴. قفل مستقیم تلاش فعلی و هر تلاش فعال روی کامپیوترهای درگیر (Attempt id ASC)
                    $lockedAttempts = Attempt::query()
                        ->where(function ($q) use ($attempt, $computerIdsToLock) {
                            $q->where('id', $attempt->id);
                            if (!empty($computerIdsToLock)) {
                                $q->orWhere(function ($q2) use ($computerIdsToLock) {
                                    $q2->whereIn('computer_id', $computerIdsToLock)
                                       ->whereIn('status', ['in_progress', 'disconnected']);
                                });
                            }
                        })
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    $lockedAttempt = $lockedAttempts->firstWhere('id', $attempt->id);
                    if (!$lockedAttempt) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                    }

                    // ۵. بازبینی اسنپ‌شات پس از قفل‌گذاری و پیش از هرگونه نوشتن
                    $currentFromComputerId = $lockedAttempt->computer_id ? (int) $lockedAttempt->computer_id : null;
                    $currentAssignmentId = $lockedAttempt->candidate_assignment_id ? (int) $lockedAttempt->candidate_assignment_id : null;

                    if ($currentFromComputerId !== $snapshotFromComputerId || $currentAssignmentId !== $snapshotAssignmentId) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                    }

                    if ($snapshotResolvedAssignmentId !== null) {
                        $lockedOwnAssignment = $lockedAssignments->firstWhere('id', $snapshotResolvedAssignmentId);
                        $currentShiftId = $lockedOwnAssignment?->shift_id ? (int) $lockedOwnAssignment->shift_id : null;
                        if (!$lockedOwnAssignment || $currentShiftId !== $snapshotShiftId) {
                            throw new RuntimeException(self::SNAPSHOT_CHANGED_MARKER);
                        }
                    }

                    // ۶. اعتبارسنجی قوانین تجاری تحت قفل
                    if (!in_array($lockedAttempt->status, ['in_progress', 'disconnected'], true)) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'فقط جلسات در حال برگزاری یا قطع‌شده قابل انتقال هستند.',
                        ];
                    }

                    if ($currentFromComputerId === $toComputerId) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'کامپیوتر مقصد نمی‌تواند همان کامپیوتر فعلی باشد.',
                        ];
                    }

                    $lockedToComputer = $lockedComputers->get($toComputerId);
                    if (!$lockedToComputer || $lockedToComputer->status !== 'online') {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'کامپیوتر مقصد در وضعیت آنلاین نیست و قابل انتخاب نمی‌باشد.',
                        ];
                    }

                    // بررسی عدم وجود تلاش فعال دیگر روی کامپیوتر مقصد
                    $conflictingAttempt = $lockedAttempts->first(function (Attempt $a) use ($toComputerId, $lockedAttempt) {
                        return (int) $a->computer_id === $toComputerId
                            && (int) $a->id !== (int) $lockedAttempt->id
                            && in_array($a->status, ['in_progress', 'disconnected'], true);
                    });

                    if ($conflictingAttempt !== null) {
                        return [
                            'error'   => true,
                            'status'  => 422,
                            'message' => 'کامپیوتر مقصد در حال حاضر دارای جلسه آزمون فعال است و قابل انتخاب نمی‌باشد.',
                        ];
                    }

                    // بررسی عدم رزرو بودن کامپیوتر مقصد توسط داوطلب دیگر در همان شیفت
                    if ($snapshotShiftId !== null) {
                        $reservedByOtherInShift = $lockedAssignments->first(function (CandidateAssignment $ca) use ($snapshotShiftId, $toComputerId, $lockedAttempt) {
                            return (int) $ca->shift_id === $snapshotShiftId
                                && $ca->computer_id !== null
                                && (int) $ca->computer_id === $toComputerId
                                && (int) $ca->candidate_id !== (int) $lockedAttempt->candidate_id;
                        });

                        if ($reservedByOtherInShift !== null) {
                            return [
                                'error'   => true,
                                'status'  => 422,
                                'message' => 'کامپیوتر مقصد در این شیفت به داوطلب دیگری تخصیص داده شده است.',
                            ];
                        }
                    }

                    // ۷. اعمال تغییرات تحت قفل
                    if ($currentFromComputerId !== null) {
                        $lockedFromComputer = $lockedComputers->get($currentFromComputerId);
                        $otherActiveOnFrom = $lockedAttempts->first(function (Attempt $a) use ($currentFromComputerId, $lockedAttempt) {
                            return (int) $a->computer_id === $currentFromComputerId
                                && (int) $a->id !== (int) $lockedAttempt->id
                                && in_array($a->status, ['in_progress', 'disconnected'], true);
                        });

                        // اگر هیچ تلاش فعال دیگری روی کامپیوتر مبدأ نباشد، وضعیت نهایی آن تعیین می‌شود:
                        // در صورت ارسال وضعیت معتبر برای کامپیوتر مبدأ (یا در صورتی که قبلاً در وضعیت غیر از exam مانند maintenance بوده)،
                        // همان وضعیت معتبر اعمال/حفظ می‌شود و در غیر این صورت به online بازمی‌گردد.
                        if ($lockedFromComputer && $otherActiveOnFrom === null) {
                            $requestedSourceStatus = $request->input('source_computer_status')
                                ?? $request->input('from_computer_status')
                                ?? $request->input('source_status')
                                ?? $request->input('source_Handling');

                            if (is_string($requestedSourceStatus) && in_array($requestedSourceStatus, ['online', 'maintenance', 'offline'], true)) {
                                $targetSourceStatus = $requestedSourceStatus;
                            } elseif (in_array($lockedFromComputer->status, ['maintenance', 'offline'], true)) {
                                $targetSourceStatus = $lockedFromComputer->status;
                            } else {
                                $targetSourceStatus = 'online';
                            }

                            $lockedFromComputer->update(['status' => $targetSourceStatus]);
                        }
                    }

                    // تغییر وضعیت کامپیوتر مقصد به در حال آزمون (exam)
                    $lockedToComputer->update(['status' => 'exam']);

                    // بروزرسانی کامپیوتر فعلی در تلاش آزمون (بدون تغییر رکورد اولیه در candidate_assignments)
                    $lockedAttempt->update([
                        'computer_id'       => $toComputerId,
                        'connection_status' => 'connected',
                        'status'            => 'in_progress',
                    ]);

                    // ثبت رکورد انتقال جلسه مطابق اسکیمای واقعی جدول session_transfers
                    $transfer = SessionTransfer::create([
                        'attempt_id'       => $lockedAttempt->id,
                        'from_computer_id' => $currentFromComputerId,
                        'to_computer_id'   => $toComputerId,
                        'transferred_by'   => $request->user()->id,
                        'reason'           => $request->input('reason'),
                        'transferred_at'   => now(),
                    ]);

                    // ثبت لاگ حسابرسی مطابق اسکیمای واقعی جدول audit_logs
                    DB::table('audit_logs')->insert([
                        'user_id'     => $request->user()->id,
                        'role'        => $request->user()->role?->name,
                        'action'      => 'session.transferred',
                        'module'      => 'attempts',
                        'target_type' => 'Attempt',
                        'target_id'   => $lockedAttempt->id,
                        'old_value'   => json_encode([
                            'computer_id' => $currentFromComputerId,
                        ]),
                        'new_value'   => json_encode([
                            'candidate_id'     => $lockedAttempt->candidate_id,
                            'from_computer_id' => $currentFromComputerId,
                            'to_computer_id'   => $toComputerId,
                            'reason'           => $request->input('reason'),
                        ]),
                        'ip_address'  => $request->ip(),
                        'user_agent'  => $request->userAgent(),
                        'created_at'  => now(),
                    ]);

                    return [
                        'error'    => false,
                        'transfer' => $transfer,
                    ];
                }, 3);

                if ($outcome['error']) {
                    return response()->json([
                        'success' => false,
                        'message' => $outcome['message'],
                    ], $outcome['status']);
                }

                /** @var SessionTransfer $transfer */
                $transfer = $outcome['transfer'];

                return response()->json([
                    'success' => true,
                    'message' => 'جلسه آزمون داوطلب با موفقیت به کامپیوتر جدید منتقل شد.',
                    'data'    => $transfer->load([
                        'fromComputer:id,computer_code,hostname',
                        'toComputer:id,computer_code,hostname',
                        'transferredBy:id,name,username',
                    ]),
                ], 201);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === self::SNAPSHOT_CHANGED_MARKER) {
                    continue;
                }
                throw $e;
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'به دلیل تغییرات هم‌زمان روی اطلاعات جلسه یا صندلی، انتقال انجام نشد. لطفاً مجدداً تلاش کنید.',
        ], 409);
    }
}