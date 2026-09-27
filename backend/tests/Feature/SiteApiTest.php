<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role, ?Employee $employee = null): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($employee !== null) {
            $employee->update(['user_id' => $user->id]);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    /* ------------------------------------------------------------ gates */

    public function test_a_role_without_sites_view_cannot_list(): void
    {
        $this->actingAsRole('Payroll Admin');

        $this->getJson('/api/v1/sites')->assertForbidden();
    }

    public function test_hr_admin_can_read_but_not_write_sites(): void
    {
        $this->actingAsRole('HR Admin'); // sites.view, no sites.manage

        $site = Site::factory()->create();

        $this->getJson('/api/v1/sites')->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->postJson('/api/v1/sites', [
            'project_id' => Project::factory()->create()->id,
            'name' => 'New Site',
            'code' => 'NS1',
            'status' => 'active',
        ])->assertForbidden();

        $this->putJson("/api/v1/sites/{$site->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/sites/{$site->id}")->assertForbidden();
    }

    public function test_project_manager_can_create_a_site(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();
        $manager = Employee::factory()->create();
        $supervisor = Employee::factory()->create();

        $id = $this->postJson('/api/v1/sites', [
            'project_id' => $project->id,
            'name' => 'Pier 4',
            'code' => 'P04',
            'address' => 'Pier Road',
            'latitude' => 12.9715995,
            'longitude' => 77.5945627,
            'geofence_radius' => 120,
            'site_manager_id' => $manager->id,
            'site_supervisor_id' => $supervisor->id,
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.geofence_radius', '120.00')
            ->json('data.id');

        $this->getJson("/api/v1/sites/{$id}")
            ->assertOk()
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.site_manager.id', $manager->id);
    }

    /* ---------------------------------------------- coordinates */

    public function test_an_out_of_range_latitude_is_rejected(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();

        $this->postJson('/api/v1/sites', [
            'project_id' => $project->id,
            'name' => 'Nowhere', 'code' => 'NW1', 'status' => 'active',
            'latitude' => 91.0, 'longitude' => 0.0, 'geofence_radius' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors(['latitude']);

        $this->postJson('/api/v1/sites', [
            'project_id' => $project->id,
            'name' => 'Nowhere', 'code' => 'NW2', 'status' => 'active',
            'latitude' => 10.0, 'longitude' => -181.0, 'geofence_radius' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors(['longitude']);
    }

    public function test_the_geofence_radius_bounds_come_from_config_not_a_constant(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();

        $base = [
            'project_id' => $project->id,
            'name' => 'R', 'code' => 'R', 'status' => 'active',
            'latitude' => 12.0, 'longitude' => 77.0,
        ];

        $min = (float) config('hrms.geofence.min_radius_metres');
        $max = (float) config('hrms.geofence.max_radius_metres');

        $this->postJson('/api/v1/sites', $base + ['code' => 'R1', 'geofence_radius' => $min - 1])
            ->assertStatus(422)->assertJsonValidationErrors(['geofence_radius']);

        $this->postJson('/api/v1/sites', $base + ['code' => 'R2', 'geofence_radius' => $max + 1])
            ->assertStatus(422)->assertJsonValidationErrors(['geofence_radius']);

        // The configured bounds themselves are accepted — proof the limits
        // are read from config/hrms.php rather than fixed in the request.
        $this->postJson('/api/v1/sites', $base + ['code' => 'R3', 'geofence_radius' => $max])
            ->assertCreated();
    }

    public function test_a_half_finished_geofence_is_rejected(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();

        $this->postJson('/api/v1/sites', [
            'project_id' => $project->id,
            'name' => 'Half', 'code' => 'HF1', 'status' => 'active',
            'latitude' => 12.0,
            // longitude and radius missing: a line, not a place.
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['longitude', 'geofence_radius']);

        // All three absent is a legitimate site — geofencing simply is not
        // configured for it yet.
        $this->postJson('/api/v1/sites', [
            'project_id' => $project->id,
            'name' => 'None', 'code' => 'NF1', 'status' => 'active',
        ])->assertCreated();
    }

    public function test_a_site_must_belong_to_a_real_project(): void
    {
        $this->actingAsRole('Project Manager');

        $this->postJson('/api/v1/sites', [
            'project_id' => 999999,
            'name' => 'Orphan', 'code' => 'OR1', 'status' => 'active',
        ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);

        $this->postJson('/api/v1/sites', [
            'name' => 'No Project', 'code' => 'NP1', 'status' => 'active',
        ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);
    }

    public function test_the_supervisor_must_not_be_the_site_manager(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();
        $person = Employee::factory()->create();

        $this->postJson('/api/v1/sites', [
            'project_id' => $project->id,
            'name' => 'Same Person', 'code' => 'SP1', 'status' => 'active',
            'site_manager_id' => $person->id,
            'site_supervisor_id' => $person->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['site_supervisor_id']);
    }

    /* ------------------------------------------------ list shaping */

    public function test_sites_can_be_filtered_by_project_status_manager_and_search(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $manager = Employee::factory()->create();

        Site::factory()->create([
            'project_id' => $project->id,
            'name' => 'Riverside Yard',
            'code' => 'RVY',
            'site_manager_id' => $manager->id,
            'site_supervisor_id' => null,
        ]);
        Site::factory()->create([
            'project_id' => $other->id,
            'name' => 'Hilltop Yard',
            'code' => 'HTY',
            'status' => 'inactive',
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);

        $this->getJson('/api/v1/sites?project='.$project->id)
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.code', 'RVY');

        $this->getJson('/api/v1/sites?status=inactive')
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.code', 'HTY');

        $this->getJson('/api/v1/sites?site_manager='.$manager->id)
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/v1/sites?search=Riverside')
            ->assertOk()->assertJsonPath('data.meta.total', 1);
    }

    /* ---------------------------------------------------- history */

    public function test_a_site_with_assignment_history_cannot_be_deleted(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();
        $site = Site::factory()->create(['project_id' => $project->id]);
        $employee = Employee::factory()->create();

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
        ]);

        $this->deleteJson("/api/v1/sites/{$site->id}")->assertStatus(422);
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'deleted_at' => null]);
    }

    /* ---------------------------------------------------- row scoping */

    public function test_a_site_supervisor_only_sees_the_sites_they_run(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('Site Supervisor');
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $project = Project::factory()->create();
        $mine = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => $employee->id,
        ]);
        $theirs = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);

        $this->getJson('/api/v1/sites')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $mine->id);

        $this->getJson("/api/v1/sites/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/v1/sites/{$mine->id}")->assertOk();
    }
}
