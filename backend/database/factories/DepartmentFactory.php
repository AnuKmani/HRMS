<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'name' => fake()->unique()->company().' Dept',
            'code' => 'DEP'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'description' => fake()->optional()->sentence(6),
            'status' => Department::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Department::STATUS_INACTIVE]);
    }
}
