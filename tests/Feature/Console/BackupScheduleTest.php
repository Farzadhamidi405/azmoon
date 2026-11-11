<?php

namespace Tests\Feature\Console;

use App\Console\BackupScheduleRegistrar;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class BackupScheduleTest extends TestCase
{
    private function timezoneName(Event $event): string
    {
        $tz = $event->timezone;

        return $tz instanceof DateTimeZone ? $tz->getName() : (string) $tz;
    }

    private function backupEvents(Schedule $schedule): array
    {
        return array_values(array_filter(
            $schedule->events(),
            fn ($event) => str_contains((string) $event->command, 'backup:run-scheduled')
        ));
    }

    public function test_default_config_is_disabled_with_documented_defaults(): void
    {
        $this->assertFalse(config('backup.schedule_enabled'));
        $this->assertSame('02:00', config('backup.schedule_time'));
        $this->assertSame('UTC', config('backup.schedule_timezone'));
    }

    public function test_registrar_does_not_register_when_disabled(): void
    {
        $schedule = new Schedule();

        $this->assertFalse(BackupScheduleRegistrar::register($schedule, false, '02:00', 'UTC'));
        $this->assertFalse(BackupScheduleRegistrar::register($schedule, null, '02:00', 'UTC'));
        $this->assertCount(0, $schedule->events());
    }

    public function test_registrar_registers_daily_event_with_time_timezone_and_overlap_guard(): void
    {
        $schedule = new Schedule();

        $this->assertTrue(BackupScheduleRegistrar::register($schedule, true, '03:30', 'Asia/Tehran'));

        $events = $this->backupEvents($schedule);
        $this->assertCount(1, $schedule->events());
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertSame('Asia/Tehran', $this->timezoneName($event));
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
        $this->assertSame(30, BackupScheduleRegistrar::OVERLAP_EXPIRES_MINUTES);
    }

    public function test_register_from_config_uses_config_values(): void
    {
        config([
            'backup.schedule_enabled' => true,
            'backup.schedule_time' => '05:45',
            'backup.schedule_timezone' => 'UTC',
        ]);

        $schedule = new Schedule();

        $this->assertTrue(BackupScheduleRegistrar::registerFromConfig($schedule));

        $events = $this->backupEvents($schedule);
        $this->assertCount(1, $events);
        $this->assertSame('45 5 * * *', $events[0]->expression);
        $this->assertSame('UTC', $this->timezoneName($events[0]));
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(30, $events[0]->expiresAt);
    }

    public function test_valid_time_boundaries_are_accepted(): void
    {
        $cases = [
            '00:00' => '0 0 * * *',
            '07:05' => '5 7 * * *',
            '12:00' => '0 12 * * *',
            '23:59' => '59 23 * * *',
        ];

        foreach ($cases as $time => $expression) {
            $schedule = new Schedule();

            $this->assertTrue(
                BackupScheduleRegistrar::register($schedule, true, (string) $time, 'UTC'),
                'Time: ['.$time.']'
            );

            $events = $this->backupEvents($schedule);
            $this->assertCount(1, $events, 'Time: ['.$time.']');
            $this->assertSame($expression, $events[0]->expression, 'Time: ['.$time.']');
        }
    }

    public function test_invalid_times_are_rejected_without_registration(): void
    {
        $badTimes = ['24:00', '23:60', '7:30', '07:5', '07:30:00', '', 'abc', '07:30 ', ' 07:30', '07-30', null, 730, true, ['02:00']];

        foreach ($badTimes as $bad) {
            $schedule = new Schedule();

            $this->assertFalse(
                BackupScheduleRegistrar::register($schedule, true, $bad, 'UTC'),
                'Time: ['.json_encode($bad).']'
            );
            $this->assertCount(0, $schedule->events(), 'Time: ['.json_encode($bad).']');
        }
    }

    public function test_invalid_timezones_are_rejected_without_registration(): void
    {
        $badZones = ['Not/AZone', '', 'tehran', 'Asia/Tehran ', null, 3, ['UTC']];

        foreach ($badZones as $bad) {
            $schedule = new Schedule();

            $this->assertFalse(
                BackupScheduleRegistrar::register($schedule, true, '02:00', $bad),
                'Timezone: ['.json_encode($bad).']'
            );
            $this->assertCount(0, $schedule->events(), 'Timezone: ['.json_encode($bad).']');
        }
    }

    public function test_invalid_config_logs_one_controlled_warning_per_ttl(): void
    {
        Log::spy();

        $schedule = new Schedule();

        $this->assertFalse(BackupScheduleRegistrar::register($schedule, true, 'BAD_VALUE_XYZ', 'UTC'));
        $this->assertFalse(BackupScheduleRegistrar::register($schedule, true, 'BAD_VALUE_XYZ', 'UTC'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'schedule_time')
                && ! str_contains((string) $message, 'BAD_VALUE_XYZ'))
            ->once();

        $this->assertTrue(Cache::has(BackupScheduleRegistrar::WARNING_CACHE_KEY));
        $this->assertSame(21600, BackupScheduleRegistrar::WARNING_TTL_SECONDS);

        Cache::forget(BackupScheduleRegistrar::WARNING_CACHE_KEY);

        $this->assertFalse(BackupScheduleRegistrar::register($schedule, true, 'BAD_VALUE_XYZ', 'UTC'));

        Log::shouldHaveReceived('warning')->twice();
        $this->assertCount(0, $schedule->events());
    }

    public function test_disabled_config_never_logs_even_with_invalid_values(): void
    {
        Log::spy();

        $schedule = new Schedule();

        $this->assertFalse(BackupScheduleRegistrar::register($schedule, false, 'BAD_VALUE_XYZ', 'Not/AZone'));

        Log::shouldNotHaveReceived('warning');
        $this->assertFalse(Cache::has(BackupScheduleRegistrar::WARNING_CACHE_KEY));
        $this->assertCount(0, $schedule->events());
    }

    public function test_cache_failure_never_throws_out_of_the_registrar(): void
    {
        Cache::shouldReceive('add')
            ->once()
            ->andThrow(new RuntimeException('cache table is missing'));

        $schedule = new Schedule();

        $result = BackupScheduleRegistrar::register($schedule, true, 'BAD_VALUE_XYZ', 'UTC');

        $this->assertFalse($result);
        $this->assertCount(0, $schedule->events());
    }

    public function test_real_console_routes_do_not_register_backup_event_by_default(): void
    {
        $this->assertFalse(
            config('backup.schedule_enabled'),
            'This test requires BACKUP_SCHEDULE_ENABLED to be unset or false.'
        );

        Artisan::call('schedule:list');
        $output = Artisan::output();

        $real = app(Schedule::class);

        $detect = array_values(array_filter(
            $real->events(),
            fn ($event) => str_contains((string) $event->command, 'attempts:detect-disconnected')
        ));

        $this->assertCount(1, $detect);
        $this->assertCount(0, $this->backupEvents($real));
        $this->assertStringNotContainsString('backup:run-scheduled', $output);
        $this->assertStringContainsString('attempts:detect-disconnected', $output);
    }
}