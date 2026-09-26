<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'department_id' => Department::factory(),
            'name' => fake()->jobTitle(),
            'code' => 'DES'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'description' => fake()->optional()->sentence(6),
            'status' => Designation::STATUS_ACTIVE,
        ];
    }
}
