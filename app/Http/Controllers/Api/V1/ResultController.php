<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Exam;
use App\Services\ResultService;
use Illuminate\Http\JsonResponse;

/**
 * ResultController — نمایش نتایج و آمار امتحان.
 * Guard: admin — فقط کاربران ادمین احراز هویت‌شده.
 * بند 28، 29، 30، 31 SRS.
 */
class ResultController extends Controller
{
    public function __construct(private ResultService $resultService)
    {
    }

    // ─── نتیجه یک Attempt ────────────────────────────────────

    /**
     * نمایش نتیجه کامل یک Attempt.
     * شامل نتیجه کلی + نتیجه هر section + جزئیات پاسخ‌ها.
     * بند 29 SRS.
     */
    public function show(Attempt $attempt): JsonResponse
    {
        $data = $this->resultService->getFullResult($attempt);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    // ─── نتایج یک آزمون ──────────────────────────────────────

    /**
     * لیست نتایج همه متقاضیان یک آزمون.
     * بند 28 SRS.
     */
    public function examResults(Exam $exam): JsonResponse
    {
        $attempts = Attempt::where('exam_id', $exam->id)
            ->whereIn('status', ['completed', 'auto_submitted'])
            ->with(['candidate', 'result'])
            ->get();

        $data = $attempts->map(fn($attempt) => [
            'attempt_id'  => $attempt->id,
            'candidate'   => [
                'id'   => $attempt->candidate->id,
                'name' => $attempt->candidate->name,
                'code' => $attempt->candidate->candidate_code,
            ],
            'result'      => $attempt->result,
            'submitted_at'=> $attempt->submitted_at,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    // ─── آمار کلی آزمون ──────────────────────────────────────

    /**
     * آمار کلی یک آزمون.
     * بند 30 SRS.
     */
    public function statistics(Exam $exam): JsonResponse
    {
        $stats = $this->resultService->getExamStatistics($exam->id);

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    // ─── Question Analytics ───────────────────────────────────

    /**
     * تحلیل سوالات یک آزمون.
     * بند 31 SRS.
     */
    public function questionAnalytics(Exam $exam): JsonResponse
    {
        $analytics = $this->resultService->getQuestionAnalytics($exam->id);

        return response()->json([
            'success' => true,
            'data'    => $analytics,
        ]);
    }
}
