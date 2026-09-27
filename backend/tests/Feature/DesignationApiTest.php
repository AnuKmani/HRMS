<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesignationApiTest extends TestCase
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

    public function test_a_role_without_the_permission_cannot_list_designations(): void
    {
        $this->actingAsRole('Finance'); // employees + payroll, no designations

        $this->getJson('/api/v1/designations')->assertForbidden();
    }

    public function test_hr_admin_can_crud_designations(): void
    {
        $this->actingAsRole('HR Admin');

        $department = Department::factory()->create();

        $id = $this->postJson('/api/v1/designations', [
            'department_id' => $department->id,
            'name' => 'Site Engineer',
            'code' => 'SE',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.department.name', $department->name)
            ->json('data.id');

        $this->putJson("/api/v1/designations/{$id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/designations/{$id}")->assertOk();
        $this->assertSoftDeleted('designations', ['id' => $id]);
    }

    public function test_a_designation_may_be_company_wide_with_no_department(): void
    {
        $this->actingAsRole('HR Admin');

        $this->postJson('/api/v1/designations', [
            'name' => 'Graduate Trainee',
            'code' => 'GT',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.department_id', null);
    }

    public function test_an_archived_department_cannot_be_assigned(): void
    {
        $this->actingAsRole('HR Admin');

        $archived = Department::factory()->create();
        $archived->delete();

        $this->postJson('/api/v1/designations', [
            'department_id' => $archived->id,
            'name' => 'Ghost Role',
            'code' => 'GH',
            'status' => 'active',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_designations_can_be_filtered_by_department_status_and_search(): void
    {
        $this->actingAsRole('HR Admin');

        $it = Department::factory()->create(['name' => 'Information Technology', 'code' => 'IT']);
        $ops = Department::factory()->create(['name' => 'Operations', 'code' => 'OPS']);

        Designation::factory()->create(['department_id' => $it->id, 'name' => 'Principal Engineer', 'code' => 'PE']);
        Designation::factory()->create(['department_id' => $it->id, 'name' => 'QA Lead', 'code' => 'QL', 'status' => 'inactive']);
        Designation::factory()->create(['department_id' => $ops->id, 'name' => 'Store Keeper', 'code' => 'SK']);

        $this->getJson('/api/v1/designations?department='.$it->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        // Both spellings of the same filter are accepted.
        $this->getJson('/api/v1/designations?department_id='.$it->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson('/api/v1/designations?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.code', 'QL');

        $this->getJson('/api/v1/designations?search=Keeper')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_a_designation_with_employees_cannot_be_archived(): void
    {
        $this->actingAsRole('HR Admin');

        $designation = Designation::factory()->create();
        Employee::factory()->create(['designation_id' => $designation->id]);

        $this->deleteJson("/api/v1/designations/{$designation->id}")->assertStatus(422);

        $this->assertDatabaseHas('designations', ['id' => $designation->id, 'deleted_at' => null]);
    }

    public function test_a_bad_status_is_rejected(): void
    {
        $this->actingAsRole('HR Admin');

        $this->postJson('/api/v1/designations', [
            'name' => 'Whatever',
            'code' => 'WH',
            'status' => 'suspended',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }
}
