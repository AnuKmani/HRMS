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
 * Assignments are append-only history, and this file is where that promise is
 * held to: a move inserts and closes rather than rewriting, nothing but the
 * conclusion can be edited, and there is no DELETE route at all.
 */
class AssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function signInAsRole(string $role, ?Employee $employee = null): User
    {
        $employee ??= Employee::factory()->create();

        $user = User::factory()->create();
        $user->assignRole($role);
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A project with two sites, plus somebody to post. Everything shares one
     * project so the site-belongs-to-project rule is what is under test, not
     * the fixture.
     *
     * @return array{project: Project, sites: Site, employee: Employee}
     */
    private function fixture(): array
    {
        $project = Project::factory()->create();

        $sites = Site::factory()->count(2)->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);

        return [
            'project' => $project,
            'sites' => $sites,
            'employee' => Employee::factory()->create(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Project $project, Site $site, Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'assignment_type' => 'primary',
            'start_date' => '2026-01-01',
        ], $overrides);
    }

    /* ------------------------------------------------------------ gates */

    public function test_a_role_without_assignments_view_cannot_list(): void
    {
        $this->signInAsRole('Finance');

        $this->getJson('/api/v1/employee-site-assignments')->assertForbidden();
    }

    public function test_hr_admin_can_create_an_assignment_and_the_row_records_who_made_it(): void
    {
        $user = $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $id = $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $sites[0], $employee))
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.assignment_type', 'primary')
            ->assertJsonPath('data.site.id', $sites[0]->id)
            ->json('data.id');

        // The one field an audit log will want the day it is written: who
        // did this. Recorded now, used later — nothing is faked in between.
        $this->assertDatabaseHas('employee_site_assignments', [
            'id' => $id,
            'created_by' => $user->id,
        ]);
    }

    public function test_the_site_must_belong_to_the_named_project(): void
    {
        $this->signInAsRole('HR Admin');

        $first = $this->fixture();
        $second = $this->fixture();

        $this->postJson('/api/v1/employee-site-assignments', $this->payload(
            $second['project'],   // project B
            $first['sites'][0],   // site of project A
            $first['employee'],
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_id']);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->signInAsRole('HR Admin');

        $this->postJson('/api/v1/employee-site-assignments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'employee_id', 'project_id', 'site_id', 'start_date', 'assignment_type',
            ]);

        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $sites[0], $employee, [
            'assignment_type' => 'secondment',
            'status' => 'paused',
            'end_date' => '2025-01-01',
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'assignment_type', 'status', 'end_date',
        ]);
    }

    /* ------------------------------------------------------- history */

    public function test_a_new_primary_posting_closes_the_previous_one_without_erasing_it(): void
    {
        $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $firstId = $this->postJson('/api/v1/employee-site-assignments', $this->payload(
            $project, $sites[0], $employee, ['start_date' => '2026-01-01'],
        ))->assertCreated()->json('data.id');

        $this->postJson('/api/v1/employee-site-assignments', $this->payload(
            $project, $sites[1], $employee, ['start_date' => '2026-07-01'],
        ))->assertCreated()->assertJsonPath('data.status', 'active');

        // Two rows, not one rewritten row.
        $this->assertDatabaseCount('employee_site_assignments', 2);

        $old = EmployeeSiteAssignment::query()->findOrFail($firstId);

        $this->assertSame('ended', $old->status);
        // Contiguous with the new posting: ends the day before it begins.
        $this->assertSame('2026-06-30', $old->end_date->toDateString());
        // Still on the site it was actually on.
        $this->assertSame($sites[0]->id, $old->site_id);
        $this->assertSame($project->id, $old->project_id);
    }

    public function test_a_temporary_posting_leaves_the_primary_posting_alone(): void
    {
        $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $primaryId = $this->postJson('/api/v1/employee-site-assignments', $this->payload(
            $project, $sites[0], $employee,
        ))->assertCreated()->json('data.id');

        $this->postJson('/api/v1/employee-site-assignments', $this->payload(
            $project, $sites[1], $employee,
            ['assignment_type' => 'temporary', 'start_date' => '2026-02-01'],
        ))->assertCreated();

        $primary = EmployeeSiteAssignment::query()->findOrFail($primaryId);

        $this->assertSame('active', $primary->status);
        $this->assertNull($primary->end_date);
    }

    public function test_a_posting_can_be_closed(): void
    {
        $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $id = $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $sites[0], $employee))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/employee-site-assignments/{$id}", ['status' => 'ended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.end_date', now()->toDateString());

        $this->putJson("/api/v1/employee-site-assignments/{$id}", [
            'status' => 'cancelled',
            'end_date' => '2026-03-15',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.end_date', '2026-03-15');
    }

    public function test_history_cannot_be_rewritten(): void
    {
        $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $other = Employee::factory()->create();

        $id = $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $sites[0], $employee))
            ->assertCreated()
            ->json('data.id');

        // Every identity field is refused by name rather than dropped
        // quietly — a client that thinks it moved somebody must be told it
        // did not.
        foreach ([
            'employee_id' => $other->id,
            'project_id' => $project->id,
            'site_id' => $sites[1]->id,
            'assignment_type' => 'temporary',
            'start_date' => '2025-05-05',
        ] as $field => $value) {
            $this->putJson("/api/v1/employee-site-assignments/{$id}", [
                'status' => 'ended',
                $field => $value,
            ])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        // Nothing moved.
        $row = EmployeeSiteAssignment::query()->findOrFail($id);
        $this->assertSame($employee->id, $row->employee_id);
        $this->assertSame($sites[0]->id, $row->site_id);
        $this->assertSame('active', $row->status);
    }

    public function test_an_ended_posting_cannot_be_reopened(): void
    {
        $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $id = $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $sites[0], $employee))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/employee-site-assignments/{$id}", ['status' => 'ended'])
            ->assertOk();

        $this->putJson("/api/v1/employee-site-assignments/{$id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_there_is_no_route_that_deletes_posting_history(): void
    {
        $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $id = $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $sites[0], $employee))
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/employee-site-assignments/{$id}")
            ->assertStatus(405)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('employee_site_assignments', 1);
    }

    /* ---------------------------------------------------- row scoping */

    public function test_a_site_supervisor_can_post_only_to_sites_they_run(): void
    {
        $supervisor = Employee::factory()->create();
        $this->signInAsRole('Site Supervisor', $supervisor);

        $project = Project::factory()->create();
        $theirSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => $supervisor->id,
        ]);
        $someoneElsesSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);
        $employee = Employee::factory()->create();

        $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $someoneElsesSite, $employee))
            ->assertForbidden();

        $this->postJson('/api/v1/employee-site-assignments', $this->payload($project, $theirSite, $employee))
            ->assertCreated();
    }

    public function test_a_site_supervisor_can_close_only_postings_on_sites_they_run(): void
    {
        $supervisor = Employee::factory()->create();
        $this->signInAsRole('Site Supervisor', $supervisor);

        $project = Project::factory()->create();
        $theirSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => $supervisor->id,
        ]);
        $someoneElsesSite = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);
        $employee = Employee::factory()->create();

        $mine = EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $theirSite->id,
        ]);
        $theirs = EmployeeSiteAssignment::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'project_id' => $project->id,
            'site_id' => $someoneElsesSite->id,
        ]);

        $this->putJson('/api/v1/employee-site-assignments/'.$theirs->id, ['status' => 'ended'])
            ->assertForbidden();

        $this->putJson('/api/v1/employee-site-assignments/'.$mine->id, ['status' => 'ended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ended');
    }

    /* ---------------------------------------------------- list shaping */

    public function test_the_list_filters_by_employee_project_site_status_and_type(): void
    {
        $this->signInAsRole('HR Admin');
        ['project' => $project, 'sites' => $sites, 'employee' => $employee] = $this->fixture();

        $target = EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $sites[0]->id,
            'assignment_type' => 'additional',
            'status' => 'ended',
            'start_date' => '2026-01-01',
        ]);
        EmployeeSiteAssignment::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'project_id' => Project::factory()->create()->id,
            'site_id' => Site::factory()->create()->id,
            'assignment_type' => 'primary',
            'status' => 'active',
        ]);

        $cases = [
            'employee='.$employee->id => $target->id,
            'project='.$project->id => $target->id,
            'site='.$sites[0]->id => $target->id,
            'status=ended' => $target->id,
            'assignment_type=additional' => $target->id,
        ];

        foreach ($cases as $query => $expectedId) {
            $response = $this->getJson('/api/v1/employee-site-assignments?'.$query);
            $response->assertOk();

            $this->assertSame(
                [$expectedId],
                array_column($response->json('data.items'), 'id'),
                "Filter [{$query}] did not isolate the expected assignment.",
            );
        }
    }
}
