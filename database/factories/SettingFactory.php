<?php

namespace Database\Factories;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'key'        => 'test_' . $this->faker->unique()->numerify('setting_###'),
            'value'      => $this->faker->word(),
            'updated_by' => null,
        ];
    }
}
