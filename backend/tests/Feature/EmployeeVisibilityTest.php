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

/**
 * Row-level authorization for the employee master.
 *
 * Every test here proves the same thing from a different seat: the coarse
 * `employees.view` permission answers "may this role open the module", and
 * config/hrms.php then decides *which people* each role actually reaches.
 * Both the collection and the single record are narrowed — a policy that
 * guarded `show` while `index` returned everything would leave the easier
 * endpoint wide open.
 */
class EmployeeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * A user whose employee row already exists, signed in as that seat.
     */
    private function signInAsRole(string $role, ?Employee $employee = null): User
    {
        $employee ??= Employee::factory()->create();

        $user = User::factory()->create();
        $user->assignRole($role);
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        return $user;
    }

    /* ------------------------------------------------- project manager */

    public function test_a_project_manager_reaches_only_their_own_workforce(): void
    {
        $manager = Employee::factory()->create();
        $this->signInAsRole('Project Manager', $manager);

        $theirs = Project::factory()->create(['project_manager_id' => $manager->id]);
        $someoneElses = Project::factory()->create();

        $onTheirs = Employee::factory()->create(['primary_project_id' => $theirs->id]);
        $onSomeoneElses = Employee::factory()->create(['primary_project_id' => $someoneElses->id]);
        $unplaced = Employee::factory()->create();

        $ids = array_column(
            $this->getJson('/api/v1/employees')->assertOk()->json('data.items'),
            'id',
        );

        $this->assertEqualsCanonicalizing(
            [$manager->id, $onTheirs->id],
            $ids,
            'A project manager should see themselves plus the people on their project.',
        );
        $this->assertNotContains($onSomeoneElses->id, $ids);
        $this->assertNotContains($unplaced->id, $ids);

        // Narrowed in both directions — a direct request is a 403, not an
        // oversight the list happened to hide.
        $this->getJson('/api/v1/employees/'.$onTheirs->id)->assertOk();
        $this->getJson('/api/v1/employees/'.$onSomeoneElses->id)->assertForbidden();
    }

    public function test_a_project_manager_reaches_someone_posted_to_their_project(): void
    {
        $manager = Employee::factory()->create();
        $this->signInAsRole('Project Manager', $manager);

        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $site = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);

        // No primary placement at all — the posting history is what puts
        // this person on the manager's radar.
        $posted = Employee::factory()->create([
            'primary_project_id' => null,
            'primary_site_id' => null,
        ]);
        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $posted->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
        ]);

        $ids = array_column(
            $this->getJson('/api/v1/employees')->assertOk()->json('data.items'),
            'id',
        );

        $this->assertContains($posted->id, $ids);
    }

    /* -------------------------------------------------- site supervisor */

    public function test_a_site_supervisor_sees_the_workforce_of_their_site_only(): void
    {
        $supervisor = Employee::factory()->create();
        $this->signInAsRole('Site Supervisor', $supervisor);

        $project = Project::factory()->create();
        $theirSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => $supervisor->id,
        ]);
        $anotherSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);

        $atTheirs = Employee::factory()->create([
            'primary_project_id' => $project->id,
            'primary_site_id' => $theirSite->id,
        ]);
        $atTheOthers = Employee::factory()->create([
            'primary_project_id' => $project->id,
            'primary_site_id' => $anotherSite->id,
        ]);

        $ids = array_column(
            $this->getJson('/api/v1/employees')->assertOk()->json('data.items'),
            'id',
        );

        $this->assertEqualsCanonicalizing([$supervisor->id, $atTheirs->id], $ids);
        $this->assertNotContains($atTheOthers->id, $ids);

        $this->getJson('/api/v1/employees/'.$atTheOthers->id)->assertForbidden();
    }

    /* ------------------------------------------------------- employee */

    public function test_an_ordinary_employee_reads_themselves_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $self = Employee::factory()->create(['user_id' => $user->id]);
        $colleague = Employee::factory()->create();

        Sanctum::actingAs($user);

        // No `employees.view` at all: the module is closed, not merely
        // filtered.
        $this->getJson('/api/v1/employees')->assertForbidden();

        $this->getJson('/api/v1/employees/'.$self->id)
            ->assertOk()
            ->assertJsonPath('data.id', $self->id);

        $this->getJson('/api/v1/employees/'.$colleague->id)->assertForbidden();

        // And no payroll figure either — the third permission is not implied
        // by being the person in question.
        $response = $this->getJson('/api/v1/employees/'.$self->id)->assertOk();
        $this->assertNull($response->json('data.salary'));
        $this->assertFalse($response->json('data.salary_visible'));
    }

    /* ----------------------------------------------------- management */

    public function test_management_reads_every_employee_but_cannot_write_one(): void
    {
        Employee::factory()->count(3)->create();
        $this->signInAsRole('Management', Employee::query()->firstOrFail()); // employees.view, nothing else on employees

        $this->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);

        $id = Employee::query()->firstOrFail()->id;

        $this->postJson('/api/v1/employees', [])->assertForbidden();
        $this->putJson("/api/v1/employees/{$id}", ['first_name' => 'Nope'])->assertForbidden();
        $this->deleteJson("/api/v1/employees/{$id}")->assertForbidden();
    }

    /* ---------------------------------------------------- assignments */

    public function test_a_supervisor_reads_posting_history_for_their_own_site_only(): void
    {
        $supervisor = Employee::factory()->create();
        $this->signInAsRole('Site Supervisor', $supervisor);

        $project = Project::factory()->create();
        $theirSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => $supervisor->id,
        ]);
        $anotherSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);

        $theirs = EmployeeSiteAssignment::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'project_id' => $project->id,
            'site_id' => $theirSite->id,
        ]);
        $someoneElses = EmployeeSiteAssignment::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'project_id' => $project->id,
            'site_id' => $anotherSite->id,
        ]);

        $ids = array_column(
            $this->getJson('/api/v1/employee-site-assignments')->assertOk()->json('data.items'),
            'id',
        );

        $this->assertSame([$theirs->id], $ids);

        $this->getJson('/api/v1/employee-site-assignments/'.$theirs->id)->assertOk();
        $this->getJson('/api/v1/employee-site-assignments/'.$someoneElses->id)->assertForbidden();
    }

    public function test_an_employee_can_read_their_own_posting(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $self = Employee::factory()->create(['user_id' => $user->id]);

        $project = Project::factory()->create();
        $site = Site::factory()->create(['project_id' => $project->id]);

        $own = EmployeeSiteAssignment::factory()->create([
            'employee_id' => $self->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
        ]);

        Sanctum::actingAs($user);

        // `assignments.view` is not held, so the collection is closed — but
        // the row that is theirs is still theirs.
        $this->getJson('/api/v1/employee-site-assignments')->assertForbidden();
        $this->getJson('/api/v1/employee-site-assignments/'.$own->id)
            ->assertOk()
            ->assertJsonPath('data.id', $own->id);
    }
}
