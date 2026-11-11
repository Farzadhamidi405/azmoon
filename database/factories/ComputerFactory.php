<?php

namespace Database\Factories;

use App\Models\Lab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory برای مدل Computer.
 * یک کامپیوتر با وضعیت online می‌سازد.
 */
class ComputerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lab_id'        => Lab::factory(),
            'computer_code' => 'PC-' . $this->faker->unique()->numerify('####'),
            'hostname'      => $this->faker->optional()->domainWord(),
            'ip_address'    => $this->faker->localIpv4(),
            'mac_address'   => $this->faker->optional()->macAddress(),
            'os'            => 'Windows 11',
            'browser'       => 'Chrome',
            'agent_version' => 'v1.0.0',
            'status'        => 'online',
            'last_seen_at'  => now(),
        ];
    }
}