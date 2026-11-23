<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\AttemptResult;
use App\Models\AttemptSectionResult;
use App\Models\ExamSnapshotQuestion;
use Illuminate\Support\Facades\DB;

/**
 * ResultService — محاسبه نتیجه امتحان بعد از Submit.
 * این Service بعد از submit یا auto_submit صدا زده می‌شود.
 * نتیجه از Snapshot خوانده می‌شود — نه از Question Bank زنده.
 * بند 28، 29، 30، 31 SRS.
 */
class ResultService
{
    /**
     * محاسبه و ذخیره نتیجه کامل یک Attempt.
     * Transaction اجباری: attempt_results + attempt_section_results باید
     * همزمان و سازگار نوشته شوند.
     */
    public function calculate(Attempt $attempt): AttemptResult
    {
        return DB::transaction(function () use ($attempt) {

            // بارگذاری سوالات Snapshot این امتحان با section
            $snapshotQuestions = ExamSnapshotQuestion::where(
                'exam_snapshot_id',
                $attempt->exam_snapshot_id
            )->get()->keyBy('id');

            // بارگذاری پاسخ‌های متقاضی
            $answers = AttemptAnswer::where('attempt_id', $attempt->id)
                ->get()
                ->keyBy('exam_snapshot_question_id');

            // محاسبه نتیجه بر اساس section
            $sectionResults = [];
            $totalScore     = 0;

            foreach ($snapshotQuestions as $qId => $question) {
                $sectionId = $question->exam_section_id;

                // مقداردهی اولیه section
                if (!isset($sectionResults[$sectionId])) {
                    $sectionResults[$sectionId] = [
                        'correct_count'    => 0,
                        'wrong_count'      => 0,
                        'unanswered_count' => 0,
                        'score'            => 0,
                    ];
                }

                $answer = $answers->get($qId);

                // بی‌پاسخ
                if (!$answer || (empty($answer->selected_option_ids) && empty($answer->answer_text))) {
                    $sectionResults[$sectionId]['unanswered_count']++;
                    continue;
                }

                // سوالات تشریحی و بدون گزینه: نیاز به تصحیح دستی توسط مصحح
                if (empty($question->options_snapshot)) {
                    // برای سوالات تشریحی تا زمان نمره‌دهی دستی، نمره خودکار صفر یا اشتباه ثبت نمی‌شود
                    $answer->update([
                        'is_correct'    => null,
                        'score_awarded' => null,
                    ]);
                    continue;
                }

                // بررسی صحت پاسخ از options_snapshot برای سوالات عینی/تستی
                $isCorrect = $this->checkAnswer($question, $answer);

                if ($isCorrect) {
                    $sectionResults[$sectionId]['correct_count']++;
                    $sectionResults[$sectionId]['score'] += $question->score;
                    $totalScore += $question->score;

                    // به‌روزرسانی is_correct و score_awarded در attempt_answers
                    $answer->update([
                        'is_correct'    => true,
                        'score_awarded' => $question->score,
                    ]);
                } else {
                    $sectionResults[$sectionId]['wrong_count']++;
                    $answer->update([
                        'is_correct'    => false,
                        'score_awarded' => 0,
                    ]);
                }
            }

            // ذخیره نتیجه هر section
            foreach ($sectionResults as $sectionId => $result) {
                AttemptSectionResult::updateOrCreate(
                    [
                        'attempt_id'      => $attempt->id,
                        'exam_section_id' => $sectionId,
                    ],
                    $result
                );
            }

            // محاسبه درصد و pass/fail
            $totalMarks = $attempt->exam->total_marks;
            $passMarks  = $attempt->exam->pass_marks;
            $percentage = $totalMarks > 0
                ? round(($totalScore / $totalMarks) * 100, 2)
                : 0;
            $passFail = $totalScore >= $passMarks ? 'pass' : 'fail';

            // ذخیره نتیجه کلی
            $result = AttemptResult::updateOrCreate(
                ['attempt_id' => $attempt->id],
                [
                    'total_score'    => $totalScore,
                    'percentage'     => $percentage,
                    'pass_fail'      => $passFail,
                    'calculated_by'  => 'system',
                    'calculated_at'  => now(),
                ]
            );

            // به‌روزرسانی خلاصه در attempts
            $attempt->update([
                'score'         => $totalScore,
                'correct_count' => collect($sectionResults)->sum('correct_count'),
                'wrong_count'   => collect($sectionResults)->sum('wrong_count'),
            ]);

            return $result;
        });
    }

    /**
     * بررسی صحت پاسخ متقاضی بر اساس options_snapshot.
     * options_snapshot آرایه‌ای از گزینه‌هاست که is_correct دارند.
     * نتیجه از Snapshot خوانده می‌شود — نه از question_options زنده.
     */
    private function checkAnswer(
        ExamSnapshotQuestion $question,
        AttemptAnswer $answer
    ): bool {
        $options = $question->options_snapshot;

        if (empty($options)) {
            return false;
        }

        // پیدا کردن گزینه‌های صحیح از Snapshot
        $correctOptionIds = collect($options)
            ->filter(fn($opt) => !empty($opt['is_correct']))
            ->pluck('id')
            ->sort()
            ->values()
            ->toArray();

        // مرتب‌سازی پاسخ متقاضی برای مقایسه
        $selectedIds = collect($answer->selected_option_ids ?? [])
            ->sort()
            ->values()
            ->toArray();

        return $correctOptionIds === $selectedIds;
    }

    /**
     * دریافت نتیجه کامل یک Attempt برای نمایش به Admin.
     * شامل نتیجه کلی + نتیجه هر section + جزئیات پاسخ‌ها.
     * بند 29 SRS.
     */
    public function getFullResult(Attempt $attempt): array
    {
        $result = $attempt->result()->with('attempt')->first();

        $sectionResults = $attempt->sectionResults()
            ->with('examSection')
            ->get();

        $answers = $attempt->answers()
            ->with('snapshotQuestion')
            ->get();

        return [
            'attempt_id'      => $attempt->id,
            'candidate'       => [
                'id'   => $attempt->candidate->id,
                'name' => $attempt->candidate->name,
                'code' => $attempt->candidate->candidate_code,
            ],
            'exam'            => [
                'id'    => $attempt->exam->id,
                'title' => $attempt->exam->title,
            ],
            'result'          => $result,
            'section_results' => $sectionResults,
            'answers'         => $answers,
            'time_used'       => $attempt->started_at && $attempt->submitted_at
                ? $attempt->started_at->diffInSeconds($attempt->submitted_at)
                : null,
        ];
    }

    /**
     * دریافت نتیجه آزمون برای متقاضی (امن: بدون افشای کلیدهای پاسخ و با رعایت show_result_immediately).
     * بند 29 SRS.
     */
    public function getCandidateResult(Attempt $attempt): array
    {
        $result = $attempt->result;
        $exam = $attempt->exam;

        $showImmediately = (bool) ($exam?->show_result_immediately ?? false);

        $hasPendingManual = $attempt->answers()
            ->whereHas('snapshotQuestion', fn($q) => $q->whereNull('options_snapshot')->orWhere('options_snapshot', '[]'))
            ->whereNotNull('answer_text')
            ->whereNull('score_awarded')
            ->exists();

        $baseData = [
            'attempt_id'              => $attempt->id,
            'exam_title'              => $exam?->title,
            'total_marks'             => $exam?->total_marks ? (float) $exam->total_marks : null,
            'pass_marks'              => $exam?->pass_marks ? (float) $exam->pass_marks : null,
            'show_result_immediately' => $showImmediately,
            'status'                  => $attempt->status,
            'submitted_at'            => $attempt->submitted_at?->toIso8601String(),
            'has_pending_manual'      => $hasPendingManual,
            'time_used_seconds'       => $attempt->started_at && $attempt->submitted_at
                ? $attempt->started_at->diffInSeconds($attempt->submitted_at)
                : null,
        ];

        // در صورت عدم اجازه مشاهده فوری نتایج، نمرات، درصد، قبولی/ردی و ریزنمرات کاملاً پنهان می‌شوند
        if (!$showImmediately) {
            return $baseData;
        }

        $sectionResults = $attempt->sectionResults()->with('examSection:id,name')->get();

        // اگر آزمون شامل سوالات تشریحی در انتظار تصحیح دستی باشد، وضعیت قبولی/ردی قطعی ارائه نمی‌شود
        $passFailStatus = $hasPendingManual ? 'pending_manual_grading' : $result?->pass_fail;

        return array_merge($baseData, [
            'is_finalized'            => !$hasPendingManual,
            'total_score'             => $result ? (float) $result->total_score : null,
            'percentage'              => $result ? (float) $result->percentage : null,
            'pass_fail'               => $passFailStatus,
            'correct_count'           => $attempt->correct_count,
            'wrong_count'             => $attempt->wrong_count,
            'section_results'         => $sectionResults->map(fn($sr) => [
                'section_id'       => $sr->exam_section_id,
                'section_name'     => $sr->examSection?->name ?? 'بخش ' . $sr->exam_section_id,
                'score'            => (float) $sr->score,
                'correct_count'    => $sr->correct_count,
                'wrong_count'      => $sr->wrong_count,
                'unanswered_count' => $sr->unanswered_count,
            ])->values()->all(),
        ]);
    }

    /**
     * آمار کلی یک آزمون — بند 30 SRS.
     */
    public function getExamStatistics(int $examId): array
    {
        $attempts = Attempt::where('exam_id', $examId)
            ->whereIn('status', ['completed', 'auto_submitted'])
            ->with('result')
            ->get();

        $results = $attempts->map(fn($a) => $a->result)->filter();

        $scores = $results->pluck('total_score');

        $passed = $results->where('pass_fail', 'pass')->count();
        $failed = $results->where('pass_fail', 'fail')->count();
        $total  = $attempts->count();

        return [
            'exam_id'      => $examId,
            'total'        => $total,
            'completed'    => $total,
            'passed'       => $passed,
            'failed'       => $failed,
            'pass_rate'    => $total > 0 ? round(($passed / $total) * 100, 2) : 0,
            'average'      => $scores->avg() ? round($scores->avg(), 2) : 0,
            'median'       => $this->median($scores->toArray()),
            'highest'      => $scores->max() ?? 0,
            'lowest'       => $scores->min() ?? 0,
        ];
    }

    /**
     * تحلیل سوالات — Question Analytics — بند 31 SRS.
     */
    public function getQuestionAnalytics(int $examId): array
    {
        $snapshot = \App\Models\ExamSnapshot::where('exam_id', $examId)
            ->latest()
            ->first();

        if (!$snapshot) {
            return [];
        }

        $questions = ExamSnapshotQuestion::where(
            'exam_snapshot_id',
            $snapshot->id
        )->get();

        $totalAttempts = Attempt::where('exam_id', $examId)
            ->whereIn('status', ['completed', 'auto_submitted'])
            ->count();

        $analytics = [];

        foreach ($questions as $question) {
            $answers = AttemptAnswer::where(
                'exam_snapshot_question_id',
                $question->id
            )->get();

            $answeredCount   = $answers->whereNotNull('selected_option_ids')
                ->where('selected_option_ids', '!=', '[]')
                ->count();
            $correctCount    = $answers->where('is_correct', true)->count();
            $wrongCount      = $answers->where('is_correct', false)->count();
            $unansweredCount = $totalAttempts - $answeredCount;
            $avgTime         = $answers->avg('time_spent_seconds');

            $successRate = $answeredCount > 0
                ? round(($correctCount / $answeredCount) * 100, 2)
                : 0;

            $analytics[] = [
                'question_id'      => $question->id,
                'question_text'    => $question->question_text_snapshot,
                'section'          => $question->exam_section_id,
                'correct_count'    => $correctCount,
                'wrong_count'      => $wrongCount,
                'unanswered_count' => $unansweredCount,
                'success_rate'     => $successRate,
                'avg_time_seconds' => round($avgTime ?? 0, 1),
            ];
        }

        return $analytics;
    }

    /**
     * محاسبه میانه (Median) از یک آرایه عددی.
     */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $mid   = (int) floor($count / 2);

        return $count % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }
}
