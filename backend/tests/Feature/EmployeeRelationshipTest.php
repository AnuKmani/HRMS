<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_belongs_to_department_and_designation(): void
    {
        $department = Department::factory()->create();
        $designation = Designation::factory()->create(['department_id' => $department->id]);

        $employee = Employee::factory()->create([
            'department_id' => $department->id,
            'designation_id' => $designation->id,
        ]);

        $this->assertSame($department->id, $employee->department->id);
        $this->assertSame($designation->id, $employee->designation->id);
        $this->assertSame($department->id, $employee->designation->department_id);
    }

    public function test_department_and_designation_expose_their_employees(): void
    {
        $department = Department::factory()->create();
        $designation = Designation::factory()->create(['department_id' => $department->id]);

        Employee::factory()->count(2)->create([
            'department_id' => $department->id,
            'designation_id' => $designation->id,
        ]);

        $this->assertCount(2, $department->employees);
        $this->assertCount(2, $designation->employees);
        $this->assertTrue($department->designations->contains($designation));
    }

    public function test_employee_has_optional_link_to_user_account(): void
    {
        $employee = Employee::factory()->create();

        $this->assertNull($employee->user_id);
        $this->assertNull($employee->user);

        $user = User::factory()->create();
        $employee->update(['user_id' => $user->id]);

        $this->assertSame($user->id, $employee->fresh()->user->id);
        // Inverse side resolves too — no circular/necessary extras.
        $this->assertSame($employee->id, $user->fresh()->employee->id);
    }

    public function test_user_may_exist_without_employee_record(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->employee);
    }

    public function test_employee_user_link_is_one_to_one(): void
    {
        $user = User::factory()->create();

        Employee::factory()->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);

        Employee::factory()->create(['user_id' => $user->id]);
    }

    public function test_reporting_manager_forms_hierarchy(): void
    {
        $manager = Employee::factory()->create();
        $report = Employee::factory()->create(['reporting_manager_id' => $manager->id]);

        $this->assertSame($manager->id, $report->reporting_manager_id);
        $this->assertSame($manager->id, $report->reportingManager->id);
        $this->assertTrue($manager->directReports->contains($report));
    }

    public function test_soft_deleting_reporting_manager_keeps_the_reference_intact(): void
    {
        $manager = Employee::factory()->create();
        $report = Employee::factory()->create(['reporting_manager_id' => $manager->id]);

        $manager->delete();

        // Soft delete is the normal path — the manager row still exists
        // (recoverable), so the reporting line must NOT be rewritten.
        $this->assertSoftDeleted($manager);
        $this->assertSame($manager->id, $report->fresh()->reporting_manager_id);
        $this->assertNotSoftDeleted($report);
        $this->assertTrue($report->fresh()->isActive());
    }

    public function test_hard_deleting_reporting_manager_clears_the_link_only(): void
    {
        $manager = Employee::factory()->create();
        $report = Employee::factory()->create(['reporting_manager_id' => $manager->id]);

        $manager->forceDelete();

        // NullOnDelete, never cascade: the report's own row is untouched.
        $this->assertNull($report->fresh()->reporting_manager_id);
        $this->assertDatabaseHas('employees', ['id' => $report->id]);
    }

    public function test_employee_composes_structured_name_and_initials(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Anu',
            'middle_name' => 'K',
            'last_name' => 'Manni',
        ]);

        $this->assertSame('Anu K Manni', $employee->full_name);
        $this->assertSame('AM', $employee->initials);
    }

    public function test_employment_status_vocabulary_is_enforced(): void
    {
        foreach (Employee::STATUSES as $status) {
            $employee = Employee::factory()->create(['employment_status' => $status]);
            $this->assertSame($status, $employee->fresh()->employment_status);
        }

        $this->assertEqualsCanonicalizing(
            ['active', 'inactive', 'resigned', 'terminated', 'on_leave'],
            Employee::STATUSES,
        );
    }

    public function test_employee_code_and_email_are_unique(): void
    {
        $employee = Employee::factory()->create();

        $this->expectException(QueryException::class);

        Employee::factory()->create([
            'employee_code' => $employee->employee_code,
            'email' => 'different@example.com',
        ]);
    }

    public function test_soft_deleted_employee_is_hidden_from_default_queries(): void
    {
        $employee = Employee::factory()->create();
        $employee->delete();

        $this->assertNull(Employee::find($employee->id));
        $this->assertNotNull(Employee::withTrashed()->find($employee->id));
        $this->assertCount(0, Employee::all());
    }
}
