<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registers the scheduled backup event on the given Schedule.
 *
 * This class has one responsibility: deciding whether the scheduled backup event
 * is registered, and registering it. It never throws, so it is safe to call from
 * routes/console.php (which is loaded by every artisan command, including migrate).
 */
class BackupScheduleRegistrar
{
    public const COMMAND = 'backup:run-scheduled';

    public const OVERLAP_EXPIRES_MINUTES = 30;

    public const WARNING_CACHE_KEY = 'backup_schedule_invalid_config_warning';

    public const WARNING_TTL_SECONDS = 21600;

    public const TIME_PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    /**
     * Register the event using the values of config('backup.*').
     */
    public static function registerFromConfig(Schedule $schedule): bool
    {
        return self::register(
            $schedule,
            config('backup.schedule_enabled'),
            config('backup.schedule_time'),
            config('backup.schedule_timezone')
        );
    }

    /**
     * Register the daily scheduled backup event when enabled and valid.
     *
     * @return bool true only when the event was registered.
     */
    public static function register(Schedule $schedule, mixed $enabled, mixed $time, mixed $timezone): bool
    {
        try {
            if (!$enabled) {
                return false;
            }

            $invalid = [];

            if (!is_string($time) || preg_match(self::TIME_PATTERN, $time) !== 1) {
                $invalid[] = 'schedule_time';
            }

            if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
                $invalid[] = 'schedule_timezone';
            }

            if ($invalid !== []) {
                self::warnOnce($invalid);

                return false;
            }

            $schedule->command(self::COMMAND)
                ->dailyAt($time)
                ->timezone($timezone)
                ->withoutOverlapping(self::OVERLAP_EXPIRES_MINUTES);

            return true;
        } catch (Throwable $e) {
            self::warnOnce(['registration_error']);

            return false;
        }
    }

    /**
     * Log a warning at most once per WARNING_TTL_SECONDS, so an invalid configuration
     * does not produce a log line on every scheduler run (every minute).
     * Any failure here (cache unavailable, missing table during migrate) is swallowed.
     *
     * @param  array<int, string>  $invalidKeys
     */
    private static function warnOnce(array $invalidKeys): void
    {
        try {
            if (Cache::add(self::WARNING_CACHE_KEY, true, self::WARNING_TTL_SECONDS)) {
                Log::warning(
                    'Scheduled backup was not registered because of an invalid configuration: '
                    . implode(', ', $invalidKeys) . '.'
                );
            }
        } catch (Throwable $e) {
            // Intentionally ignored: registration must never break artisan or the scheduler.
        }
    }
}