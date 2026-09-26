<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            // Nullable by default: linking a login / project / site is done
            // explicitly by the test or seeder, never implicitly by the factory.
            'user_id' => null,
            'employee_code' => 'EMP'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'photo_path' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('+##########'),
            'date_of_birth' => fake()->date('Y-m-d', '-22 years'),
            'nationality' => fake()->optional()->country(),
            'address' => fake()->optional()->address(),
            'emergency_contact_name' => fake()->optional()->name(),
            'emergency_contact_phone' => fake()->optional()->numerify('+##########'),
            'emergency_contact_relation' => fake()->optional()->randomElement(['Spouse', 'Parent', 'Sibling']),
            'joining_date' => fake()->date('Y-m-d', '-3 years'),
            'department_id' => Department::factory(),
            'designation_id' => Designation::factory(),
            'employment_type' => Employee::TYPES[array_rand(Employee::TYPES)],
            'reporting_manager_id' => null,
            'primary_project_id' => null,
            'primary_site_id' => null,
            'salary' => fake()->randomFloat(2, 30000, 180000),
            'employment_status' => Employee::STATUS_ACTIVE,
        ];
    }

    public function withUser(): static
    {
        return $this->state(fn () => ['user_id' => User::factory()]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['employment_status' => $status]);
    }
}
