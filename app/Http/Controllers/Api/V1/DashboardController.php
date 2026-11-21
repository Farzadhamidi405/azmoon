<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * کنترلر آمار تجمیعی داشبورد مدیریت.
 */
class DashboardController extends Controller
{
    /**
     * دریافت آمار خلاصه وضعیت سامانه برای داشبورد.
     * GET /api/v1/admin/dashboard-stats
     */
    public function stats(): JsonResponse
    {
        $publishedExams = Exam::where('status', 'published')->count();
        $runningExams = Exam::where('status', 'running')->count();
        $totalCandidates = Candidate::count();
        $inProgressCandidates = Candidate::where('status', 'in_progress')->count();

        $recentActivity = AuditLog::with('user:id,name,username')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'published_exams'       => $publishedExams,
                'running_exams'         => $runningExams,
                'candidates_count'      => $totalCandidates,
                'in_progress_count'     => $inProgressCandidates,
                'recent_activity'       => $recentActivity,
            ],
        ]);
    }
}
