<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'name' => fake()->unique()->company().' Project',
            'code' => 'PRJ'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'client' => fake()->company(),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->city(),
            'project_manager_id' => Employee::factory(),
            'start_date' => fake()->date('Y-m-d', '+30 days'),
            'end_date' => fake()->date('Y-m-d', '+400 days'),
            'status' => Project::STATUS_ACTIVE,
        ];
    }
}
