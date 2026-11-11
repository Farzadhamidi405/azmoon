<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AntiCheatEvent;
use App\Models\Attempt;
use App\Models\AttemptRiskScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Enums\NotificationType;
use App\Services\NotificationService;
use Throwable;

/**
 * AntiCheatController — ثبت رویدادهای مشکوک و محاسبه Risk Score.
 * Guard: candidate — فقط متقاضی احراز هویت‌شده.
 * اصل مهم: هیچ رویدادی به‌تنهایی به معنای تقلب قطعی نیست.
 * سیستم فقط ثبت می‌کند و Risk Score تولید می‌کند.
 * تصمیم نهایی همیشه با Reviewer انسانی است.
 * بند 20 و 21 SRS.
 */
class AntiCheatController extends Controller
{


    /**
     * Dedupe window (seconds) for the suspicious session notification of the same attempt.
     */
    private const NOTIFICATION_DEDUPE_SECONDS = 600;

    public function __construct(private NotificationService $notificationService)
    {
    }

    // ─── Record Event ─────────────────────────────────────────

    /**
     * ثبت رویداد مشکوک از Frontend.
     * Frontend رویداد را گزارش می‌دهد — Backend تصمیم می‌گیرد.
     * بعد از ثبت رویداد، Risk Score دوباره محاسبه می‌شود.
     * بند 20 SRS.
     */
    public function recordEvent(Request $request, Attempt $attempt): JsonResponse
    {
        // بررسی مالکیت — فقط صاحب Attempt می‌تواند رویداد ثبت کند
        if ($attempt->candidate_id !== auth('candidate')->id()) {
            return response()->json(
                ['success' => false, 'message' => 'دسترسی غیرمجاز.'],
                403,
            );
        }

        // Attempt باید فعال باشد
        if (!$attempt->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'این امتحان فعال نیست.',
            ], 422);
        }

        $validated = $request->validate([
            'event_type'   => 'required|string|in:' . implode(',', array_keys(AntiCheatEvent::EVENT_WEIGHTS)),
            'meta'         => 'nullable|array',
            'occurred_at'  => 'nullable|date',
        ]);

        // ثبت رویداد و محاسبه مجدد Risk Score — Transaction اجباری
        // دلیل: ثبت رویداد و بروزرسانی Risk Score باید اتمیک باشند
                // The event and the risk score are stored atomically in one transaction.
        $result = DB::transaction(function () use ($attempt, $validated) {
            // Lock the attempt row first so concurrent events for the same attempt run one at a time.
            Attempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            AntiCheatEvent::create([
                'attempt_id' => $attempt->id,
                'event_type' => $validated['event_type'],
                'meta' => $validated['meta'] ?? null,
                'occurred_at' => $validated['occurred_at'] ?? now(),
            ]);

            return $this->recalculateRiskScore($attempt);
        });

        // After commit: notify only when the attempt crosses into the high level.
        if ($result['level'] === 'high' && $result['previous_level'] !== 'high') {
            $this->notifySuspiciousSession($attempt, $result['score']);
        }

        return response()->json([
            'success' => true,
            'message' => 'رویداد ثبت شد.',
        ]);
    }

    // ─── Get Risk Score ───────────────────────────────────────

    /**
     * دریافت Risk Score فعلی Attempt — برای Admin/Reviewer.
     * Guard: admin — فقط کاربر ادمین احراز هویت‌شده.
     * بند 21 SRS.
     */
    public function getRiskScore(Attempt $attempt): JsonResponse
    {
        // بارگذاری آخرین Risk Score و رویدادها
        $riskScore = $attempt->riskScore;
        $events    = $attempt->antiCheatEvents()
            ->orderBy('occurred_at', 'desc')
            ->get();

        // خلاصه رویدادها بر اساس نوع
        $eventSummary = $events->groupBy('event_type')->map(fn($group) => $group->count());

        return response()->json([
            'success' => true,
            'data'    => [
                'attempt_id'    => $attempt->id,
                'risk_score'    => $riskScore ? [
                    'score'       => $riskScore->score,
                    'level'       => $riskScore->level,
                    'computed_at' => $riskScore->computed_at,
                ] : [
                    'score' => 0,
                    'level' => 'low',
                    'computed_at' => null,
                ],
                'event_summary' => $eventSummary,
                'events'        => $events,
            ],
        ]);
    }

    // ─── Private Helpers ──────────────────────────────────────

    /**
     * محاسبه مجدد Risk Score بر اساس همه رویدادهای ثبت‌شده.
     * هر نوع رویداد وزن مشخصی دارد — بند 21 SRS.
     * Risk Score بین 0 تا 100 است.
     */
        private function recalculateRiskScore(Attempt $attempt): array
    {
        // Level of the latest stored score (explicit id ordering), read before the new row is created.
        $previousLevel = AttemptRiskScore::query()
            ->where('attempt_id', $attempt->id)
            ->orderByDesc('id')
            ->value('level');

        // Sum of the weights of all recorded events.
        $totalScore = $attempt->antiCheatEvents()
            ->get()
            ->sum(fn($event) => AntiCheatEvent::EVENT_WEIGHTS[$event->event_type] ?? 0);

        // Capped at 100.
        $finalScore = min(100, $totalScore);
        $level = AttemptRiskScore::calculateLevel($finalScore);

        // A new row is stored every time, so history is kept.
        AttemptRiskScore::create([
            'attempt_id' => $attempt->id,
            'score' => $finalScore,
            'level' => $level,
            'computed_at' => now(),
        ]);

        return [
            'previous_level' => $previousLevel,
            'level' => $level,
            'score' => (int) $finalScore,
        ];
    }

    /**
     * Notifies authorized users that an attempt crossed into the high risk level.
     * Neutral wording on purpose: a risk level is a signal, not proof.
     * A notification failure must never break event recording.
     */
    private function notifySuspiciousSession(Attempt $attempt, int $score): void
    {
        try {
            $this->notificationService->dispatch(
                NotificationType::SuspiciousSession,
                'نشست آزمون نیازمند بررسی است',
                "ریسک نشست شماره {$attempt->id} به سطح بالا رسیده است (امتیاز: {$score} از 100) و نیازمند بررسی است.",
                'attempt',
                $attempt->id,
                null,
                self::NOTIFICATION_DEDUPE_SECONDS,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
