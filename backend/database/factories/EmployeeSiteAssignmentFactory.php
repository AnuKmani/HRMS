<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeSiteAssignment>
 */
class EmployeeSiteAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'project_id' => Project::factory(),
            'site_id' => Site::factory(),
            'assignment_type' => EmployeeSiteAssignment::TYPE_PRIMARY,
            'start_date' => fake()->date('Y-m-d', '-6 months'),
            'end_date' => null,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
            'created_by' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => EmployeeSiteAssignment::STATUS_ENDED,
            'end_date' => fake()->date('Y-m-d', '-1 month'),
        ]);
    }
}
