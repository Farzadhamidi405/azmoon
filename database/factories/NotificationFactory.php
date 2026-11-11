<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای Notification — تست‌های Step 12.9.
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement([
                'candidate_disconnected',
                'backup_failed',
                'suspicious_session',
            ]),
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->optional()->paragraph(),
            'related_type' => null,
            'related_id' => null,
            'is_read' => false,
            'notifiable_user_id' => User::factory(),
        ];
    }

    /**
     * اعلان broadcast — بدون کاربر مشخص (قابل‌مشاهده برای همه، غیرقابل mark-read).
     */
    public function broadcast(): static
    {
        return $this->state(fn (array $attributes) => [
            'notifiable_user_id' => null,
        ]);
    }

    /**
     * اعلان با وضعیت از‌قبل‌خوانده‌شده.
     */
    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => true,
        ]);
    }
}