<?php

namespace Database\Factories;

use App\Models\Attempt;
use App\Models\ManualOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای ManualOverride — تست‌های Step 12.4.
 */
class ManualOverrideFactory extends Factory
{
    protected $model = ManualOverride::class;

    /**
     * وضعیت پیش‌فرض یک Override.
     */
    public function definition(): array
    {
        $action = $this->faker->randomElement([
            'extend_time',
            'reset_session',
            'transfer_session',
            'force_submit',
            'reopen_attempt',
            'change_computer',
            'mark_absent',
            'void_attempt',
        ]);

        return [
            'attempt_id'   => Attempt::factory(),
            'action'       => $action,
            'reason'       => $this->faker->sentence(15),
            'old_value'    => ['minutes' => 60],
            'new_value'    => ['minutes' => 70],
            'performed_by' => User::factory(),
            'performed_at' => now(),
        ];
    }

    /**
     * Override از نوع extend_time.
     */
    public function extendTime(): static
    {
        return $this->state([
            'action'    => 'extend_time',
            'old_value' => ['remaining_seconds' => 600],
            'new_value' => ['remaining_seconds' => 1200],
        ]);
    }

    /**
     * Override از نوع void_attempt.
     */
    public function voidAttempt(): static
    {
        return $this->state([
            'action'    => 'void_attempt',
            'old_value' => ['status' => 'in_progress'],
            'new_value' => ['status' => 'void'],
        ]);
    }

    /**
     * Override از نوع force_submit.
     */
    public function forceSubmit(): static
    {
        return $this->state([
            'action'    => 'force_submit',
            'old_value' => ['status' => 'in_progress'],
            'new_value' => ['status' => 'completed'],
        ]);
    }
}