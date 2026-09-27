<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Master data CRUD, and the two gates guarding it: `permission:` on the route
 * and DepartmentPolicy behind `$this->authorize()`.
 */
class DepartmentApiTest extends TestCase
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

    /* ------------------------------------------------------- read gates */

    public function test_a_role_without_the_permission_cannot_list_departments(): void
    {
        $this->actingAsRole('Employee'); // dashboard/leave/documents only

        $this->getJson('/api/v1/departments')->assertForbidden();
    }

    public function test_hr_executive_can_read_but_not_write(): void
    {
        $this->actingAsRole('HR Executive'); // departments.view, no manage

        Department::factory()->create(['name' => 'Engineering', 'code' => 'ENG']);

        $this->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'ENG');

        $this->postJson('/api/v1/departments', [
            'name' => 'Marketing',
            'code' => 'MKT',
            'status' => 'active',
        ])->assertForbidden();

        $id = Department::factory()->create()->id;
        $nameBefore = Department::query()->findOrFail($id)->name;

        $this->putJson("/api/v1/departments/{$id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/departments/{$id}")->assertForbidden();

        $this->assertSame($nameBefore, Department::query()->findOrFail($id)->name);
        $this->assertDatabaseHas('departments', ['id' => $id, 'deleted_at' => null]);
    }

    /* ------------------------------------------------------ write path */

    public function test_hr_admin_can_create_read_update_and_delete(): void
    {
        $this->actingAsRole('HR Admin');

        $id = $this->postJson('/api/v1/departments', [
            'name' => 'Information Technology',
            'code' => 'IT',
            'description' => 'Builds the product',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Information Technology')
            ->assertJsonPath('data.designations_count', 0)
            ->json('data.id');

        $this->getJson("/api/v1/departments/{$id}")->assertOk()->assertJsonPath('data.code', 'IT');

        $this->putJson("/api/v1/departments/{$id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/departments/{$id}")->assertOk();

        // Soft, not hard: employees and designations still point here.
        $this->assertSoftDeleted('departments', ['id' => $id]);
    }

    public function test_a_duplicate_code_is_rejected_with_a_field_error(): void
    {
        $this->actingAsRole('HR Admin');

        Department::factory()->create(['code' => 'OPS']);

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Also Operations',
            'code' => 'OPS',
            'status' => 'active',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors' => ['code']]);
        $this->assertDatabaseCount('departments', 1);
    }

    public function test_required_fields_are_validated_together_not_one_at_a_time(): void
    {
        $this->actingAsRole('HR Admin');

        $response = $this->postJson('/api/v1/departments', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'code', 'status']);

        $this->postJson('/api/v1/departments', [
            'name' => 'Too Long '.str_repeat('x', 200),
            'code' => 'has spaces',
            'status' => 'archived',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'code', 'status']);
    }

    /* --------------------------------------------------- list shaping */

    public function test_list_is_searchable_filtered_and_paginated(): void
    {
        $this->actingAsRole('HR Admin');

        Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR']);
        Department::factory()->create(['name' => 'Information Technology', 'code' => 'IT']);
        Department::factory()->inactive()->create(['name' => 'Legacy', 'code' => 'LEG']);

        $this->getJson('/api/v1/departments?search=Technolog')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.code', 'IT');

        $this->getJson('/api/v1/departments?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.code', 'LEG');

        $this->getJson('/api/v1/departments?sort=code&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'LEG');

        // An unknown sort falls back rather than 422ing a bookmarked link.
        $this->getJson('/api/v1/departments?sort=password')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_a_department_with_employees_cannot_be_archived(): void
    {
        $this->actingAsRole('HR Admin');

        $department = Department::factory()->create();
        Employee::factory()->create(['department_id' => $department->id]);

        $this->deleteJson("/api/v1/departments/{$department->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'deleted_at' => null]);
    }

    public function test_an_unknown_department_is_a_404_not_a_500(): void
    {
        $this->actingAsRole('HR Admin');

        $this->getJson('/api/v1/departments/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
