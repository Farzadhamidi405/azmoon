<?php

namespace Database\Factories;

use App\Models\Attempt;
use App\Models\Computer;
use App\Models\Lab;
use App\Models\SessionTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای SessionTransfer — تست‌های Step 12.5.
 */
class SessionTransferFactory extends Factory
{
    protected $model = SessionTransfer::class;

    public function definition(): array
    {
        $lab = Lab::factory()->create();

        return [
            'attempt_id'       => Attempt::factory(),
            'from_computer_id' => Computer::factory()->create(['lab_id' => $lab->id]),
            'to_computer_id'   => Computer::factory()->create(['lab_id' => $lab->id]),
            'reason'           => $this->faker->sentence(10),
            'transferred_by'   => User::factory(),
            'transferred_at'   => now(),
        ];
    }
}