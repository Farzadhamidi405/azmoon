<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Attempt;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class DetectDisconnectedAttemptsCommand extends Command
{
    protected $signature = 'attempts:detect-disconnected';

    protected $description = 'Mark in-progress attempts with a stale heartbeat as disconnected and notify authorized users';

    public function handle(NotificationService $notificationService): int
    {
        $timeout = config('attempt.heartbeat_timeout_seconds');
        $dedupe = config('attempt.disconnect_notification_dedupe_seconds');

        if (! is_int($timeout) || $timeout < 1 || ! is_int($dedupe) || $dedupe < 1) {
            $this->error('Invalid attempt configuration: heartbeat_timeout_seconds and disconnect_notification_dedupe_seconds must be integers >= 1. No changes were made.');

            return self::FAILURE;
        }

        $now = Carbon::now();
        $cutoff = $now->copy()->subSeconds($timeout);

        $marked = 0;
        $failed = 0;

        $this->eligible(Attempt::query(), $cutoff, $now)
            ->chunkById(200, function ($attempts) use ($cutoff, $now, $timeout, $dedupe, $notificationService, &$marked, &$failed) {
                foreach ($attempts as $attempt) {
                    try {
                        $updated = $this->eligible(Attempt::query(), $cutoff, $now)
                            ->whereKey($attempt->id)
                            ->update([
                                'connection_status' => 'disconnected',
                                'disconnected_at' => $now,
                            ]);
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;

                        continue;
                    }

                    if ($updated !== 1) {
                        continue;
                    }

                    $marked++;

                    try {
                        $notificationService->dispatch(
                            NotificationType::CandidateDisconnected,
                            'قطع اتصال نشست آزمون',
                            "اتصال نشست شماره {$attempt->id} قطع شده است (بیش از {$timeout} ثانیه بدون پیام حضور).",
                            'attempt',
                            $attempt->id,
                            null,
                            $dedupe
                        );
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info("Marked {$marked} attempt(s) as disconnected.");

        if ($failed > 0) {
            $this->error("Failed to update {$failed} attempt(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function eligible(Builder $query, Carbon $cutoff, Carbon $now): Builder
    {
        return $query
            ->where('status', 'in_progress')
            ->where('connection_status', 'connected')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', $cutoff)
            ->where('expires_at', '>', $now);
    }
}