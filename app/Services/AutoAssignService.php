<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * سرویس تخصیص خودکار داوطلبان به کامپیوترها در یک شیفت.
 *
 * اصول امنیتی و هم‌زمانی (Step 14.6.5c):
 * 1. متد preview کاملاً Read-Only است و هیچ تغییری در دیتابیس ایجاد نمی‌کند.
 * 2. متد confirm در داخل تراکنش اتمیک با ترتیب قفل استاندارد
 *    Shift (id ASC) -> CandidateAssignment (id ASC) -> Computer (id ASC) -> Attempt (id ASC)
 *    اجرا می‌شود تا از Race Condition و بن‌بست (Deadlock) جلوگیری گردد.
 * 3. ظرفیت باقی‌مانده شیفت بر اساس کل رکوردهای دارای صندلی در candidate_assignments
 *    (حتی در صورت Soft-Delete شدن داوطلب) محاسبه می‌شود و صندلی‌های اشغال‌شده یا دارای
 *    جلسه آزمون فعال (in_progress یا disconnected) هرگز مجدداً تخصیص نمی‌یابند.
 */
class AutoAssignService
{
    /**
     * پیش‌نمایش تخصیص خودکار برای یک شیفت (کاملاً Read-Only بدون تغییر در دیتابیس).
     *
     * @param int $shiftId شناسه شیفت
     * @return array لیست تخصیص‌های پیشنهادی و آمار
     */
    public function preview(int $shiftId): array
    {
        $shift = Shift::with('lab')->findOrFail($shiftId);

        // تمام رکوردهای تخصیص در این شیفت که دارای کامپیوتر هستند (حتی اگر داوطلب Soft-Deleted باشد)
        $reservedComputerIdsInShift = CandidateAssignment::where('shift_id', $shift->id)
            ->whereNotNull('computer_id')
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $occupiedSeatsCount = count($reservedComputerIdsInShift);
        $remainingCapacity = max(0, (int) $shift->capacity - $occupiedSeatsCount);

        // کامپیوترهایی که در حال حاضر دارای جلسه آزمون فعال یا قطع‌شده هستند
        $activeAttemptComputerIds = Attempt::whereIn('status', ['in_progress', 'disconnected'])
            ->whereNotNull('computer_id')
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $excludedComputerIds = array_values(array_unique(array_merge(
            $reservedComputerIdsInShift,
            $activeAttemptComputerIds
        )));

        // داوطلبان بدون کامپیوتر در این شیفت که فعال بوده و حذف نشده‌اند (مرتب‌سازی قطعی بر اساس id ASC)
        $unassigned = CandidateAssignment::with('candidate')
            ->where('shift_id', $shift->id)
            ->where('lab_id', $shift->lab_id)
            ->whereNull('computer_id')
            ->whereHas('candidate', function ($q) {
                $q->whereNull('deleted_at')
                  ->whereNotIn('status', ['cancelled', 'disqualified']);
            })
            ->orderBy('id', 'asc')
            ->get()
            ->filter(fn (CandidateAssignment $a) => $a->candidate !== null && $a->candidate->deleted_at === null)
            ->values();

        // کامپیوترهای آنلاین و آزاد در لابراتوار مربوطه (مرتب‌سازی قطعی بر اساس computer_code ASC, id ASC)
        $availableComputers = Computer::where('lab_id', $shift->lab_id)
            ->where('status', 'online')
            ->when(!empty($excludedComputerIds), fn ($q) => $q->whereNotIn('id', $excludedComputerIds))
            ->orderBy('computer_code', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return $this->buildPlanResult($shift, $unassigned, $availableComputers, $remainingCapacity);
    }

    /**
     * اجرای قطعی و اتمیک تخصیص خودکار برای یک شیفت با رعایت سلسله‌مراتب قفل‌ها.
     *
     * ترتیب قفل‌ها:
     * Shift (id ASC) -> CandidateAssignment (id ASC) -> Computer (id ASC) -> Attempt (id ASC)
     *
     * @param int $shiftId شناسه شیفت
     * @param int|null $assignedBy شناسه کاربر تخصیص‌دهنده
     * @return array نتیجه نهایی تخصیص و آمار
     */
    public function confirm(int $shiftId, ?int $assignedBy = null): array
    {
        return DB::transaction(function () use ($shiftId, $assignedBy) {
            // ۱. قفل رکورد شیفت
            $lockedShift = Shift::with('lab')
                ->whereKey($shiftId)
                ->lockForUpdate()
                ->firstOrFail();

            // ۲. قفل تمام رکوردهای تخصیص این شیفت به ترتیب id ASC
            $shiftAssignments = CandidateAssignment::with('candidate')
                ->where('shift_id', $lockedShift->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // صندلی‌های اشغال‌شده در شیفت (شامل داوطلبان Soft-Deleted که computer_id آن‌ها پر است)
            $occupiedAssignments = $shiftAssignments->filter(fn (CandidateAssignment $a) => $a->computer_id !== null);
            $occupiedSeatsCount = $occupiedAssignments->count();
            $remainingCapacity = max(0, (int) $lockedShift->capacity - $occupiedSeatsCount);

            $reservedComputerIdsInShift = $occupiedAssignments
                ->pluck('computer_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            // داوطلبان واجد شرایط و بدون کامپیوتر (تحت قفل، مرتب‌شده بر اساس id ASC)
            $unassigned = $shiftAssignments
                ->filter(function (CandidateAssignment $a) use ($lockedShift) {
                    return $a->computer_id === null
                        && (int) $a->lab_id === (int) $lockedShift->lab_id
                        && $a->candidate !== null
                        && $a->candidate->deleted_at === null
                        && !in_array($a->candidate->status, ['cancelled', 'disqualified'], true);
                })
                ->values();

            // ۳. قفل تمام کامپیوترهای لابراتوار به ترتیب id ASC
            $labComputers = Computer::where('lab_id', $lockedShift->lab_id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $labComputerIds = $labComputers->pluck('id')->map(fn ($id) => (int) $id)->all();

            // ۴. قفل تمام تلاش‌های فعال یا قطع‌شده روی کامپیوترهای این لابراتوار به ترتیب id ASC
            $activeAttempts = empty($labComputerIds)
                ? collect()
                : Attempt::whereIn('computer_id', $labComputerIds)
                    ->whereIn('status', ['in_progress', 'disconnected'])
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

            $activeAttemptComputerIds = $activeAttempts
                ->pluck('computer_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $excludedComputerIds = array_values(array_unique(array_merge(
                $reservedComputerIdsInShift,
                $activeAttemptComputerIds
            )));

            // استخراج کامپیوترهای آنلاین و آزاد با ترتیب قطعی computer_code ASC, id ASC
            $availableComputers = $labComputers
                ->filter(fn (Computer $c) => $c->status === 'online' && !in_array((int) $c->id, $excludedComputerIds, true))
                ->sort(function (Computer $a, Computer $b) {
                    $cmp = strcmp((string) $a->computer_code, (string) $b->computer_code);
                    return $cmp !== 0 ? $cmp : ($a->id <=> $b->id);
                })
                ->values();

            $plan = $this->buildPlanResult($lockedShift, $unassigned, $availableComputers, $remainingCapacity);

            $now = now();
            $usedComputerIds = [];

            foreach ($plan['assignments'] as $item) {
                $computerId = (int) $item['computer_id'];
                if (in_array($computerId, $usedComputerIds, true)) {
                    throw new RuntimeException('تداخل در تخصیص کامپیوتر: یک کامپیوتر بیش از یک‌بار انتخاب شده است.');
                }
                $usedComputerIds[] = $computerId;

                $affected = CandidateAssignment::whereKey($item['assignment_id'])
                    ->where('shift_id', $lockedShift->id)
                    ->whereNull('computer_id')
                    ->update([
                        'computer_id' => $computerId,
                        'assigned_by' => $assignedBy,
                        'assigned_at' => $now,
                    ]);

                if ($affected !== 1) {
                    throw new RuntimeException("تخصیص صندلی برای رکورد {$item['assignment_id']} به دلیل تغییر هم‌زمان ناموفق بود.");
                }
            }

            return $plan;
        }, 3);
    }

    /**
     * اجرای اتمیک لیست تخصیص‌های از پیش محاسبه‌شده با رعایت قفل و شرط خالی بودن صندلی.
     * در صورت شکست هر یک از ردیف‌ها، کل تراکنش Rollback می‌شود.
     *
     * @param array $assignments آرایه تخصیص‌ها
     * @param int|null $assignedBy شناسه کاربر تخصیص‌دهنده
     * @return int تعداد تخصیص‌های انجام‌شده
     */
    public function assign(array $assignments, ?int $assignedBy = null): int
    {
        if (empty($assignments)) {
            return 0;
        }

        return DB::transaction(function () use ($assignments, $assignedBy) {
            $assignmentIds = [];
            $computerIds = [];

            foreach ($assignments as $item) {
                if (empty($item['assignment_id']) || empty($item['computer_id'])) {
                    throw new RuntimeException('ساختار ورودی تخصیص نامعتبر است.');
                }
                $assignmentId = (int) $item['assignment_id'];
                $computerId = (int) $item['computer_id'];

                if (in_array($assignmentId, $assignmentIds, true)) {
                    throw new RuntimeException('تخصیص تکراری برای یک داوطلب مجاز نیست.');
                }
                if (in_array($computerId, $computerIds, true)) {
                    throw new RuntimeException('تخصیص یک کامپیوتر به دو داوطلب مجاز نیست.');
                }

                $assignmentIds[] = $assignmentId;
                $computerIds[] = $computerId;
            }

            $sortedAssignmentIds = $assignmentIds;
            sort($sortedAssignmentIds);

            $sortedComputerIds = $computerIds;
            sort($sortedComputerIds);

            // ۱. قفل شیفت‌های مرتبط به ترتیب id ASC
            $shiftIds = CandidateAssignment::whereIn('id', $sortedAssignmentIds)
                ->pluck('shift_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (!empty($shiftIds)) {
                Shift::whereIn('id', $shiftIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();
            }

            // ۲. قفل رکوردهای تخصیص به ترتیب id ASC
            $lockedAssignments = CandidateAssignment::with('candidate')
                ->whereIn('id', $sortedAssignmentIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // ۳. قفل رکوردهای کامپیوتر به ترتیب id ASC
            $lockedComputers = Computer::whereIn('id', $sortedComputerIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // ۴. قفل تلاش‌های فعال روی این کامپیوترها به ترتیب id ASC
            $activeAttempts = Attempt::whereIn('computer_id', $sortedComputerIds)
                ->whereIn('status', ['in_progress', 'disconnected'])
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($activeAttempts->isNotEmpty()) {
                throw new RuntimeException('یکی از کامپیوترهای انتخاب‌شده دارای جلسه آزمون فعال است.');
            }

            $now = now();
            $count = 0;

            foreach ($assignments as $item) {
                $assignmentId = (int) $item['assignment_id'];
                $computerId = (int) $item['computer_id'];

                $lockedAssignment = $lockedAssignments->get($assignmentId);
                $lockedComputer = $lockedComputers->get($computerId);

                if (!$lockedAssignment || !$lockedComputer) {
                    throw new RuntimeException('رکورد تخصیص یا کامپیوتر مورد نظر یافت نشد.');
                }

                if ($lockedAssignment->candidate === null || $lockedAssignment->candidate->deleted_at !== null) {
                    throw new RuntimeException('امکان تخصیص صندلی به داوطلب حذف‌شده وجود ندارد.');
                }

                if ($lockedComputer->status !== 'online') {
                    throw new RuntimeException('کامپیوتر انتخاب‌شده در وضعیت آنلاین قرار ندارد.');
                }

                $affected = CandidateAssignment::whereKey($assignmentId)
                    ->whereNull('computer_id')
                    ->update([
                        'computer_id' => $computerId,
                        'assigned_by' => $assignedBy,
                        'assigned_at' => $now,
                    ]);

                if ($affected !== 1) {
                    throw new RuntimeException("تخصیص صندلی برای رکورد {$assignmentId} ناموفق بود؛ کل عملیات لغو شد.");
                }

                $count++;
            }

            return $count;
        }, 3);
    }

    /**
     * ساخت ساختار خروجی استاندارد برای پیش‌نمایش و تأیید تخصیص خودکار.
     */
    private function buildPlanResult(
        Shift $shift,
        $unassigned,
        $availableComputers,
        int $remainingCapacity
    ): array {
        $assignments = [];
        $unassignedList = [];

        $assignableCount = min(
            $unassigned->count(),
            $availableComputers->count(),
            $remainingCapacity
        );

        for ($i = 0; $i < $assignableCount; $i++) {
            $assignment = $unassigned[$i];
            $computer = $availableComputers[$i];

            $assignments[] = [
                'assignment_id'  => $assignment->id,
                'candidate_id'   => $assignment->candidate->id,
                'candidate_name' => $assignment->candidate->name,
                'national_id'    => $assignment->candidate->national_id,
                'computer_id'    => $computer->id,
                'computer_code'  => $computer->computer_code,
                'ip_address'     => $computer->ip_address,
            ];
        }

        if ($unassigned->count() > $assignableCount) {
            foreach ($unassigned->slice($assignableCount) as $idx => $a) {
                $reason = ($idx + $assignableCount) >= $remainingCapacity && $availableComputers->count() > $remainingCapacity
                    ? 'تکمیل ظرفیت شیفت'
                    : 'کمبود کامپیوتر آنلاین در لابراتوار';

                $unassignedList[] = [
                    'assignment_id'  => $a->id,
                    'candidate_id'   => $a->candidate->id,
                    'candidate_name' => $a->candidate->name,
                    'national_id'    => $a->candidate->national_id,
                    'reason'         => $reason,
                ];
            }
        }

        return [
            'shift' => [
                'id'       => $shift->id,
                'title'    => $shift->name,
                'capacity' => $shift->capacity,
                'lab_name' => $shift->lab?->name,
            ],
            'summary' => [
                'total_unassigned'    => $unassigned->count(),
                'available_computers' => $availableComputers->count(),
                'remaining_capacity'  => $remainingCapacity,
                'will_be_assigned'    => count($assignments),
                'will_remain'         => count($unassignedList),
            ],
            'assignments' => $assignments,
            'unassigned'  => $unassignedList,
        ];
    }
}