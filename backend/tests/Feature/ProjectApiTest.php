<?php

namespace Tests\Feature;

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

class ProjectApiTest extends TestCase
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

    public function test_a_role_without_projects_view_cannot_list(): void
    {
        $this->actingAsRole('Finance'); // payroll + expenses, no projects

        $this->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_hr_admin_can_read_but_not_write_projects(): void
    {
        $this->actingAsRole('HR Admin'); // projects.view, no manage

        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->postJson('/api/v1/projects', [
            'name' => 'New Build',
            'code' => 'NB1',
            'status' => 'planned',
        ])->assertForbidden();

        $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_project_manager_can_create_and_update(): void
    {
        $this->actingAsRole('Project Manager');

        $id = $this->postJson('/api/v1/projects', [
            'name' => 'Harbour Redevelopment',
            'code' => 'HRB',
            'client' => 'Port Authority',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'HRB')
            ->json('data.id');

        $this->putJson("/api/v1/projects/{$id}", ['status' => 'on_hold'])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_hold');
    }

    /* ------------------------------------------------------- validation */

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAsRole('Project Manager');

        $this->postJson('/api/v1/projects', [
            'name' => 'Backwards',
            'code' => 'BWD',
            'start_date' => '2026-06-01',
            'end_date' => '2026-05-01',
            'status' => 'planned',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_an_end_date_with_no_start_date_at_all_is_rejected(): void
    {
        $this->actingAsRole('Project Manager');

        // `after_or_equal` would have compared against null and passed; the
        // explicit check does not.
        $this->postJson('/api/v1/projects', [
            'name' => 'No Start',
            'code' => 'NOST',
            'end_date' => '2026-12-31',
            'status' => 'planned',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_shortening_a_project_cannot_move_its_end_date_before_the_stored_start(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-09-01',
        ]);

        // Payload carries only the end date — no start_date to compare
        // against, so the row's own start date has to be the anchor.
        $this->putJson("/api/v1/projects/{$project->id}", ['end_date' => '2026-02-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        // The same shortening, anchored correctly, is fine.
        $this->putJson("/api/v1/projects/{$project->id}", ['end_date' => '2026-02-01', 'start_date' => '2026-01-01'])
            ->assertOk();
    }

    public function test_a_duplicate_project_code_is_rejected_and_existing_codes_can_be_kept(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create(['code' => 'KEEP']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Clash', 'code' => 'KEEP', 'status' => 'planned',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);

        // Updating a row with its own unchanged code is not a clash.
        $this->putJson("/api/v1/projects/{$project->id}", ['code' => 'KEEP', 'name' => 'Kept'])
            ->assertOk()
            ->assertJsonPath('data.code', 'KEEP');
    }

    /* -------------------------------------------------- list shaping */

    public function test_projects_can_be_filtered_by_status_client_manager_and_dates(): void
    {
        $this->actingAsRole('Project Manager');

        $manager = Employee::factory()->create();

        $harbour = Project::factory()->create([
            'name' => 'Harbour Redevelopment',
            'code' => 'HRB',
            'client' => 'Port Authority',
            'status' => 'active',
            'project_manager_id' => $manager->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
        ]);
        Project::factory()->create([
            'name' => 'Depot Refit',
            'code' => 'DPT',
            'client' => 'Railcorp',
            'status' => 'planned',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
        ]);

        $this->getJson('/api/v1/projects?status=active')
            ->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.code', 'HRB');

        $this->getJson('/api/v1/projects?client=Port')
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/v1/projects?project_manager='.$manager->id)
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/v1/projects?start_from=2026-01-01&start_to=2026-12-31')
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/v1/projects?search=Harbour')
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        // The manager comes back as an employee projection with no salary on
        // it — projects.view is a much wider gate than employees.view.
        $this->getJson('/api/v1/projects/'.$harbour->id)
            ->assertOk()
            ->assertJsonPath('data.project_manager.id', $manager->id)
            ->assertJsonPath('data.sites_count', 0);
    }

    public function test_a_project_with_sites_cannot_be_deleted(): void
    {
        $this->actingAsRole('Project Manager');

        $project = Project::factory()->create();
        Site::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertStatus(422);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
    }

    /* ---------------------------------------------------- row scoping */

    public function test_a_site_supervisor_only_sees_projects_owning_a_site_they_run(): void
    {
        $supervisorOf = Employee::factory()->create();
        $supervisor = Employee::factory()->create();
        $supervisorUser = User::factory()->create();
        $supervisorUser->assignRole('Site Supervisor');
        $supervisor->update(['user_id' => $supervisorUser->id]);
        Sanctum::actingAs($supervisorUser);

        $theirs = Project::factory()->create();
        Site::factory()->create([
            'project_id' => $theirs->id,
            'site_manager_id' => null,
            'site_supervisor_id' => $supervisor->id,
        ]);

        $someoneElses = Project::factory()->create();
        Site::factory()->create([
            'project_id' => $someoneElses->id,
            'site_manager_id' => $supervisorOf->id,
            'site_supervisor_id' => null,
        ]);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $theirs->id);

        // Narrowed in both directions: the collection omits it, and asking
        // for it directly is a 403 rather than an oversight.
        $this->getJson("/api/v1/projects/{$someoneElses->id}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$theirs->id}")->assertOk();
    }
}
