<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->word().'_'.fake()->unique()->numberBetween(1, 999999),
            'value' => '1',
            'type' => 'integer',
            'group' => Setting::GROUP_GENERAL,
            'label' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(8),
            'is_editable' => true,
        ];
    }
}
