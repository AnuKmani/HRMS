<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Employee CRUD: the coarse gate, the referential rules that keep the roster
 * coherent, and the salary permission that is deliberately a *third* gate
 * rather than a side effect of `employees.view`.
 */
class EmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validEmployee(array $overrides = []): array
    {
        return array_merge([
            'employee_code' => 'EMP-1001',
            'first_name' => 'Asha',
            'last_name' => 'Nair',
            'email' => 'asha.nair@example.test',
            'phone' => '+911234567890',
            'joining_date' => '2026-01-05',
            'employment_type' => 'permanent',
            'employment_status' => 'active',
        ], $overrides);
    }

    /**
     * A background employee whose employment fields are pinned.
     *
     * EmployeeFactory picks employment_type at random, which is harmless
     * until a test filters on employment_type and one of the strangers in the
     * fixture happens to draw the same value — the assertion then reports a
     * broken filter when the filter is fine. Everything a count depends on
     * has to be fixed by hand.
     */
    private function plainEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'employment_type' => 'permanent',
            'employment_status' => 'active',
        ], $overrides));
    }

    /* ------------------------------------------------------------ gates */

    public function test_a_role_without_employees_view_cannot_list(): void
    {
        $this->actingAsRole('Employee');

        $this->getJson('/api/v1/employees')->assertForbidden();
    }

    public function test_hr_admin_can_create_read_update_and_soft_delete(): void
    {
        $this->actingAsRole('HR Admin');

        $department = Department::factory()->create();
        $designation = Designation::factory()->create(['department_id' => $department->id]);
        $manager = Employee::factory()->create();

        $id = $this->postJson('/api/v1/employees', $this->validEmployee([
            'department_id' => $department->id,
            'designation_id' => $designation->id,
            'reporting_manager_id' => $manager->id,
            'salary' => 150000,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.reporting_manager.id', $manager->id)
            ->assertJsonPath('data.salary', '150000.00')
            ->json('data.id');

        $this->putJson("/api/v1/employees/{$id}", ['employment_status' => 'on_leave', 'first_name' => 'Asha K'])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'on_leave')
            ->assertJsonPath('data.first_name', 'Asha K');

        // Partial PUT: omitted fields keep what they had.
        $this->getJson("/api/v1/employees/{$id}")
            ->assertOk()
            ->assertJsonPath('data.employee_code', 'EMP-1001');

        $this->deleteJson("/api/v1/employees/{$id}")->assertOk();
        $this->assertSoftDeleted('employees', ['id' => $id]);
    }

    public function test_hr_executive_can_create_but_cannot_delete(): void
    {
        $this->actingAsRole('HR Executive');

        $id = $this->postJson('/api/v1/employees', $this->validEmployee())
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/employees/{$id}")->assertForbidden();
        $this->assertDatabaseHas('employees', ['id' => $id, 'deleted_at' => null]);
    }

    /* ------------------------------------------------------ validation */

    public function test_a_duplicate_code_or_email_is_rejected(): void
    {
        $this->actingAsRole('HR Admin');

        Employee::factory()->create([
            'employee_code' => 'EMP-1001',
            'email' => 'taken@example.test',
        ]);

        $this->postJson('/api/v1/employees', $this->validEmployee())
            ->assertStatus(422)->assertJsonValidationErrors(['employee_code']);

        $this->postJson('/api/v1/employees', $this->validEmployee([
            'employee_code' => 'EMP-2002',
            'email' => 'taken@example.test',
        ]))->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_required_and_enum_fields_are_validated(): void
    {
        $this->actingAsRole('HR Admin');

        $this->postJson('/api/v1/employees', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'employee_code', 'first_name', 'last_name', 'email',
                'joining_date', 'employment_type', 'employment_status',
            ]);

        $this->postJson('/api/v1/employees', $this->validEmployee([
            'employment_type' => 'freelance',
            'employment_status' => 'sabbatical',
            'joining_date' => 'not-a-date',
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'employment_type', 'employment_status', 'joining_date',
        ]);
    }

    public function test_references_must_point_at_records_that_actually_exist(): void
    {
        $this->actingAsRole('HR Admin');

        $this->postJson('/api/v1/employees', $this->validEmployee([
            'employee_code' => 'EMP-9001',
            'department_id' => 999999,
            'designation_id' => 999999,
            'reporting_manager_id' => 999999,
            'primary_project_id' => 999999,
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'department_id', 'designation_id', 'reporting_manager_id', 'primary_project_id',
        ]);
    }

    public function test_an_archived_department_cannot_be_assigned_to_an_employee(): void
    {
        $this->actingAsRole('HR Admin');

        $department = Department::factory()->create();
        $department->delete();

        $this->postJson('/api/v1/employees', $this->validEmployee([
            'department_id' => $department->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['department_id']);
    }

    public function test_the_primary_site_must_belong_to_the_primary_project(): void
    {
        $this->actingAsRole('HR Admin');

        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        $site = Site::factory()->create(['project_id' => $project->id]);

        // Site without a project: nothing to check the pairing against.
        $this->postJson('/api/v1/employees', $this->validEmployee([
            'employee_code' => 'EMP-3001',
            'primary_site_id' => $site->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['primary_project_id']);

        // Site of another project: the pairing is wrong.
        $this->postJson('/api/v1/employees', $this->validEmployee([
            'employee_code' => 'EMP-3002',
            'primary_project_id' => $otherProject->id,
            'primary_site_id' => $site->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['primary_site_id']);

        // The pairing the rule exists to allow.
        $this->postJson('/api/v1/employees', $this->validEmployee([
            'employee_code' => 'EMP-3003',
            'primary_project_id' => $project->id,
            'primary_site_id' => $site->id,
        ]))->assertCreated()->assertJsonPath('data.primary_site.id', $site->id);
    }

    public function test_the_reporting_line_cannot_loop(): void
    {
        $this->actingAsRole('HR Admin');

        $senior = Employee::factory()->create();
        $junior = Employee::factory()->create(['reporting_manager_id' => $senior->id]);

        // Nobody reports to themselves.
        $this->putJson("/api/v1/employees/{$senior->id}", ['reporting_manager_id' => $senior->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reporting_manager_id']);

        // Nor does the junior become the senior's manager: A -> B -> A.
        $this->putJson("/api/v1/employees/{$senior->id}", ['reporting_manager_id' => $junior->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reporting_manager_id']);

        // A legitimate line still goes through.
        $third = Employee::factory()->create();
        $this->putJson("/api/v1/employees/{$third->id}", ['reporting_manager_id' => $senior->id])
            ->assertOk()
            ->assertJsonPath('data.reporting_manager.id', $senior->id);
    }

    /* ----------------------------------------------- salary gating */

    public function test_hr_executive_may_maintain_the_roster_but_not_the_payroll_figure(): void
    {
        $this->actingAsRole('HR Executive'); // no employees.salary.view

        $this->postJson('/api/v1/employees', $this->validEmployee(['salary' => 90000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salary']);

        $id = $this->postJson('/api/v1/employees', $this->validEmployee(['employee_code' => 'EMP-4001']))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/employees/{$id}", ['salary' => 99999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salary']);

        // Writing the figure is refused even though reading the record is
        // allowed — the two are separate permissions, not one.
        $this->getJson("/api/v1/employees/{$id}")
            ->assertOk()
            ->assertJsonPath('data.salary', null)
            ->assertJsonPath('data.salary_visible', false);
    }

    public function test_finance_can_read_the_figure_but_not_edit_the_record(): void
    {
        $employee = Employee::factory()->create(['salary' => 175000.50]);
        $this->actingAsRole('Finance');

        $this->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.salary', '175000.50')
            ->assertJsonPath('data.salary_visible', true);

        $this->putJson("/api/v1/employees/{$employee->id}", ['first_name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_the_list_projection_never_carries_a_salary_at_all(): void
    {
        Employee::factory()->create(['salary' => 123456]);
        $this->actingAsRole('HR Admin'); // has both employees.view and the salary one

        $items = $this->getJson('/api/v1/employees')
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);
        $this->assertArrayNotHasKey('salary', $items[0]);
        $this->assertArrayNotHasKey('salary_visible', $items[0]);
        // Nor the other private HR fields — they belong to the detail view.
        $this->assertArrayNotHasKey('date_of_birth', $items[0]);
        $this->assertArrayNotHasKey('address', $items[0]);
    }

    /* ------------------------------------------------ list shaping */

    public function test_the_list_filters_and_paginates(): void
    {
        $this->actingAsRole('HR Admin');

        $department = Department::factory()->create();
        $designation = Designation::factory()->create(['department_id' => $department->id]);
        $manager = $this->plainEmployee();
        $project = Project::factory()->create(['project_manager_id' => $this->plainEmployee()->id]);
        $site = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => $this->plainEmployee()->id,
            'site_supervisor_id' => $this->plainEmployee()->id,
        ]);

        $target = Employee::factory()->create([
            'employee_code' => 'EMP-T1',
            'first_name' => 'Priya',
            'last_name' => 'Menon',
            'email' => 'priya.menon@example.test',
            'phone' => '+911111111111',
            'department_id' => $department->id,
            'designation_id' => $designation->id,
            'reporting_manager_id' => $manager->id,
            'primary_project_id' => $project->id,
            'primary_site_id' => $site->id,
            'employment_type' => 'contract',
            'employment_status' => 'on_leave',
        ]);

        $this->plainEmployee([
            'first_name' => 'Otto',
            'last_name' => 'Normal',
            'email' => 'otto.normal@example.test',
            'phone' => '+912222222222',
        ]);

        // Structured filters are exact: each one must isolate one row.
        $cases = [
            'department_id='.$department->id => $target->id,
            'designation='.$designation->id => $target->id,
            'employment_type=contract' => $target->id,
            'employment_status=on_leave' => $target->id,
            'reporting_manager='.$manager->id => $target->id,
            'project='.$project->id => $target->id,
            'site='.$site->id => $target->id,
        ];

        foreach ($cases as $query => $expectedId) {
            $response = $this->getJson('/api/v1/employees?'.$query);
            $response->assertOk();

            $this->assertSame(
                [$expectedId],
                array_column($response->json('data.items'), 'id'),
                "Filter [{$query}] did not isolate the expected employee.",
            );
        }

        // Free-text search is a `like` across six columns, so asserting an
        // exact set would be asserting on whatever Faker named the other
        // people in the table. Asserting the target is found — and that a
        // nonsense term finds nothing — is what search actually promises.
        $found = array_column(
            $this->getJson('/api/v1/employees?search=Priya')->assertOk()->json('data.items'),
            'id',
        );
        $this->assertContains($target->id, $found);

        $this->getJson('/api/v1/employees?search=EMP-T1')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $target->id);

        $this->getJson('/api/v1/employees?search=zzzz-no-such-person')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        $this->getJson('/api/v1/employees?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 1)
            ->assertJsonPath('data.meta.current_page', 2);
    }

    public function test_an_unknown_employee_is_a_404(): void
    {
        $this->actingAsRole('HR Admin');

        $this->getJson('/api/v1/employees/999999')->assertNotFound();
    }
}
