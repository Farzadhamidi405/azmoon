<?php

namespace Database\Factories;

use App\Models\AntiCheatEvent;
use App\Models\Attempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای AntiCheatEvent.
 * برای تست‌های Anti-Cheat استفاده می‌شود.
 */
class AntiCheatEventFactory extends Factory
{
    protected $model = AntiCheatEvent::class;

    public function definition(): array
    {
        return [
            'attempt_id'  => Attempt::factory(),
            'event_type'  => $this->faker->randomElement(
                array_keys(AntiCheatEvent::EVENT_WEIGHTS)
            ),
            'meta'        => null,
            'occurred_at' => now(),
        ];
    }
}