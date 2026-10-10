<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Joining (an automatic employee code), leaving (history kept), and the
 * narrow case of deleting a record made by mistake.
 */
class EmployeeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function hr(): User
    {
        $user = User::factory()->create();
        $user->assignRole('HR Admin');
        Auth::forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    private function newHire(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Arun',
            'last_name' => 'Lal',
            'email' => 'arun'.uniqid().'@example.test',
            'phone' => '+971500000000',
            'joining_date' => '2026-10-01',
            'employment_type' => 'permanent',
            'employment_status' => 'active',
        ];
    }

    public function test_hr_leaves_the_code_blank_and_the_next_one_is_assigned(): void
    {
        $this->hr();
        Employee::factory()->create(['employee_code' => 'EMP00041']);
        Employee::factory()->create(['employee_code' => 'EMP00007'])->delete();

        $first = $this->postJson('/api/v1/employees', $this->newHire())->assertCreated()->json('data.employee_code');
        $second = $this->postJson('/api/v1/employees', $this->newHire())->assertCreated()->json('data.employee_code');

        $this->assertSame('EMP00042', $first);
        $this->assertSame('EMP00043', $second);

        // A code HR does type is still accepted.
        $this->postJson('/api/v1/employees', $this->newHire(['employee_code' => 'SITE-9']))
            ->assertCreated()->assertJsonPath('data.employee_code', 'SITE-9');
    }

    public function test_an_employee_with_history_cannot_be_deleted(): void
    {
        $this->hr();
        $employee = Employee::factory()->create();
        Attendance::factory()->create(['employee_id' => $employee->id]);

        $this->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertStatus(409)
            ->assertJsonPath('errors.history.0', 'attendance');

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_a_record_made_by_mistake_can_be_deleted_and_its_login_stops(): void
    {
        $this->hr();
        $login = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee = Employee::factory()->create(['user_id' => $login->id]);

        $this->deleteJson("/api/v1/employees/{$employee->id}")->assertOk();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertSame(User::STATUS_INACTIVE, $login->fresh()->status);
    }

    public function test_marking_as_left_keeps_history_stops_login_and_ends_postings(): void
    {
        $this->hr();
        $login = User::factory()->create(['email' => 'leaver@example.test', 'status' => User::STATUS_ACTIVE]);
        $login->assignRole('Employee');
        $employee = Employee::factory()->create(['user_id' => $login->id, 'employment_status' => 'active']);
        $attendance = Attendance::factory()->create(['employee_id' => $employee->id]);
        $posting = EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
            'end_date' => null,
        ]);

        $this->postJson("/api/v1/employees/{$employee->id}/offboard", [
            'status' => 'resigned',
            'exit_date' => '2026-10-09',
        ])->assertOk()
            ->assertJsonPath('data.employment_status', 'resigned')
            ->assertJsonPath('data.exit_date', '2026-10-09');

        // History untouched, still theirs, still visible.
        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertSame($employee->id, $attendance->fresh()->employee_id);
        $this->getJson("/api/v1/employees/{$employee->id}")->assertOk();

        // Their login no longer works; their posting ended that day.
        $this->assertSame(User::STATUS_INACTIVE, $login->fresh()->status);
        $this->assertSame(EmployeeSiteAssignment::STATUS_ENDED, $posting->fresh()->status);
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'leaver@example.test', 'password' => 'password'])
            ->assertStatus(401);

        // And back again.
        $this->hr();
        $this->postJson("/api/v1/employees/{$employee->id}/reinstate")
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'active')
            ->assertJsonPath('data.exit_date', null);
        $this->assertSame(User::STATUS_ACTIVE, $login->fresh()->status);
    }

    public function test_only_hr_with_update_rights_marks_someone_as_left(): void
    {
        $employee = Employee::factory()->create();
        $someone = User::factory()->create();
        $someone->assignRole('Employee');
        Sanctum::actingAs($someone);

        $this->postJson("/api/v1/employees/{$employee->id}/offboard", ['status' => 'resigned'])->assertForbidden();
    }

    public function test_the_restore_command_brings_back_deleted_employees(): void
    {
        $gone = Employee::factory()->create(['employee_code' => 'EMP00099']);
        $gone->delete();

        $this->artisan('hrms:restore-employees')->assertSuccessful();
        $this->assertSoftDeleted('employees', ['id' => $gone->id]);

        $this->artisan('hrms:restore-employees', ['--all' => true])->assertSuccessful();
        $this->assertNotSoftDeleted('employees', ['id' => $gone->id]);
    }
}
