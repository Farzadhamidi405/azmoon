<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\AttemptController;
use App\Models\Attempt;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class AutoSubmitExpiredAttemptsCommand extends Command
{
    protected $signature = 'attempts:auto-submit-expired';

    protected $description = 'Automatically submit in-progress or disconnected attempts whose expiration time has passed and calculate results';

    public function handle(AttemptController $attemptController): int
    {
        $now = Carbon::now();

        $query = Attempt::query()
            ->whereIn('status', ['in_progress', 'disconnected'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);

        $total = 0;
        $failed = 0;

        $query->chunkById(100, function ($attempts) use ($attemptController, &$total, &$failed) {
            foreach ($attempts as $attempt) {
                try {
                    $attemptController->autoSubmit($attempt);
                    $total++;
                } catch (Throwable $e) {
                    $this->error($e->getMessage());
                    report($e);
                    $failed++;
                }
            }
        });

        $this->info("Auto-submitted {$total} expired attempt(s).");

        if ($failed > 0) {
            $this->error("Failed to auto-submit {$failed} attempt(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
