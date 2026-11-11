<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\Computer;
use App\Models\Lab;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * CandidateAssignmentService — تخصیص متقاضیان به آزمون، شفت، لابراتوار، و کامپیوتر.
 * شامل Auto-Assign و تخصیص دستی.
 * هر عملیات حساس در Transaction انجام می‌شود — بند 11، 59.1 SRS و ERD V2.
 */
class CandidateAssignmentService
{
    /**
     * تخصیص دستی یک متقاضی به آزمون، شفت، لابراتوار.
     * کامپیوتر در این مرحله اختیاری است — می‌توان بعداً از Auto-Assign استفاده کرد.
     *
     * @param Candidate $candidate
     * @param array $data شامل: exam_id, shift_id, lab_id, computer_id (optional), assigned_by
     * @return CandidateAssignment
     * @throws \Exception اگر متقاضی قبلاً در این آزمون ثبت شده باشد
     */
    public function assign(Candidate $candidate, array $data): CandidateAssignment
    {
        return DB::transaction(function () use ($candidate, $data) {
            // بررسی تکراری نبودن تخصیص — یک متقاضی فقط یک بار در هر آزمون
            $exists = CandidateAssignment::where('candidate_id', $candidate->id)
                ->where('exam_id', $data['exam_id'])
                ->exists();

            if ($exists) {
                throw new \Exception(
                    "متقاضی {$candidate->candidate_code} قبلاً در این آزمون ثبت شده است."
                );
            }

            // بررسی ظرفیت شفت
            $shift = Shift::findOrFail($data['shift_id']);
            if ($shift->isFull()) {
                throw new \Exception(
                    "ظرفیت شفت {$shift->name} پر است."
                );
            }

            // اگر کامپیوتر مشخص شده، بررسی آزاد بودن آن
            if (!empty($data['computer_id'])) {
                $computer = Computer::findOrFail($data['computer_id']);
                if (!$computer->isAvailable()) {
                    throw new \Exception(
                        "کامپیوتر {$computer->computer_code} در دسترس نیست."
                    );
                }
            }

            return CandidateAssignment::create([
                'candidate_id' => $candidate->id,
                'exam_id'      => $data['exam_id'],
                'shift_id'     => $data['shift_id'],
                'lab_id'       => $data['lab_id'],
                'computer_id'  => $data['computer_id'] ?? null,
                'assigned_by'  => $data['assigned_by'] ?? null,
                'assigned_at'  => now(),
            ]);
        });
    }

    /**
     * تخصیص خودکار کامپیوتر به متقاضیان یک شفت.
     * منطق: بر اساس Shift، Lab، ظرفیت، و وضعیت Online بودن کامپیوترها.
     * نتیجه برای بازبینی به Admin نمایش داده می‌شود — تأیید نهایی اجباری است.
     * بند 59.1 SRS.
     *
     * @param Shift $shift
     * @return array پیش‌نمایش تخصیص — هنوز ذخیره نشده
     */
    public function previewAutoAssign(Shift $shift): array
    {
        // متقاضیانی که هنوز کامپیوتر ندارند
        $unassigned = CandidateAssignment::where('shift_id', $shift->id)
            ->whereNull('computer_id')
            ->with('candidate')
            ->get();

        if ($unassigned->isEmpty()) {
            return [
                'preview'  => [],
                'message'  => 'همهٔ متقاضیان این شفت کامپیوتر دارند.',
                'success'  => true,
            ];
        }

        // کامپیوترهای آزاد در لابراتوار این شفت
        $availableComputers = Computer::where('lab_id', $shift->lab_id)
            ->where('status', 'online')
            ->whereNotIn('id', function ($query) use ($shift) {
                // کامپیوترهایی که در همین شفت استفاده شده‌اند
                $query->select('computer_id')
                    ->from('candidate_assignments')
                    ->where('shift_id', $shift->id)
                    ->whereNotNull('computer_id');
            })
            ->get();

        if ($availableComputers->count() < $unassigned->count()) {
            return [
                'preview' => [],
                'message' => "تعداد کامپیوترهای آزاد ({$availableComputers->count()}) "
                           . "کمتر از تعداد متقاضیان ({$unassigned->count()}) است.",
                'success' => false,
            ];
        }

        // ساخت پیش‌نمایش بدون ذخیره در دیتابیس
        $preview = [];
        $computers = $availableComputers->values();

        foreach ($unassigned as $index => $assignment) {
            $preview[] = [
                'assignment_id'  => $assignment->id,
                'candidate_code' => $assignment->candidate->candidate_code,
                'candidate_name' => $assignment->candidate->name,
                'computer_id'    => $computers[$index]->id,
                'computer_code'  => $computers[$index]->computer_code,
            ];
        }

        return [
            'preview' => $preview,
            'message' => count($preview) . ' تخصیص آماده است — برای تأیید ارسال کنید.',
            'success' => true,
        ];
    }

    /**
     * تأیید و اعمال نهایی Auto-Assign.
     * فقط بعد از تأیید Admin فراخوانی می‌شود.
     * از Transaction استفاده می‌شود — همه یا هیچ.
     *
     * @param array $preview خروجی previewAutoAssign()['preview']
     * @return int تعداد تخصیص‌های انجام‌شده
     */
    public function confirmAutoAssign(array $preview): int
    {
        return DB::transaction(function () use ($preview) {
            $count = 0;

            foreach ($preview as $item) {
                // بررسی مجدد آزاد بودن کامپیوتر در لحظهٔ تأیید
                $computer = Computer::find($item['computer_id']);

                if (!$computer || !$computer->isAvailable()) {
                    throw new \Exception(
                        "کامپیوتر {$item['computer_code']} دیگر در دسترس نیست. "
                        . "لطفاً دوباره Auto-Assign را اجرا کنید."
                    );
                }

                CandidateAssignment::where('id', $item['assignment_id'])
                    ->update([
                        'computer_id' => $item['computer_id'],
                        'assigned_by' => null, // null = Auto-Assign
                        'assigned_at' => now(),
                    ]);

                $count++;
            }

            return $count;
        });
    }

    /**
     * تغییر کامپیوتر یک متقاضی — تخصیص دستی توسط Lab Manager.
     *
     * @param CandidateAssignment $assignment
     * @param int $newComputerId
     * @param int $assignedBy
     * @return CandidateAssignment
     */
    public function changeComputer(
        CandidateAssignment $assignment,
        int $newComputerId,
        int $assignedBy
    ): CandidateAssignment {
        return DB::transaction(function () use ($assignment, $newComputerId, $assignedBy) {
            $computer = Computer::findOrFail($newComputerId);

            if (!$computer->isAvailable()) {
                throw new \Exception(
                    "کامپیوتر {$computer->computer_code} در دسترس نیست."
                );
            }

            $assignment->update([
                'computer_id' => $newComputerId,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
            ]);

            return $assignment->fresh();
        });
    }
}