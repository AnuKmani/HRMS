<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteVisit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/v1/site-visits
 *
 * A visit is the same act as a check-in, bounded the other way: it starts
 * where you are standing and it stops when you walk out. Both ends are
 * verified by the same geofence and authorisation services as attendance,
 * because two implementations of "is this person allowed to be here?" would
 * eventually disagree — and the looser one would be the one somebody found
 * first.
 *
 * What is asserted nowhere in this file, because it does not exist: any
 * record of where the visitor was *between* the two points.
 */
class SiteVisitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-27 11:20:00'));
    }

    /**
     * @return array{project: Project, site: Site, employee: Employee, user: User}
     */
    private function world(): array
    {
        $project = Project::factory()->create();

        $site = Site::factory()->create([
            'project_id' => $project->id,
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'geofence_radius' => 100,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);

        $employee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
            'primary_project_id' => null,
            'primary_site_id' => null,
        ]);

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'assignment_type' => EmployeeSiteAssignment::TYPE_ADDITIONAL,
            'start_date' => '2026-09-01',
            'end_date' => null,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee->update(['user_id' => $user->id]);

        return [
            'project' => $project,
            'site' => $site,
            'employee' => $employee,
            'user' => $user,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(Site $site, array $extra = []): array
    {
        return $extra + [
            'site_id' => $site->id,
            'latitude' => 12.9717,
            'longitude' => 77.5946,
            'accuracy' => 5.0,
            'purpose' => 'Concrete pour inspection',
            'remarks' => 'First bay poured at 11:05',
        ];
    }

    /* ------------------------------------------------------------ start */

    public function test_starting_a_visit_records_a_bounded_two_point_episode(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/site-visits/start', $this->payload($world['site']));

        $response->assertCreated();

        $visit = SiteVisit::query()->sole();

        $this->assertSame($world['employee']->id, $visit->employee_id);
        $this->assertSame($world['project']->id, $visit->project_id);
        $this->assertSame($world['site']->id, $visit->site_id);
        $this->assertSame(SiteVisit::STATUS_OPEN, $visit->status);
        $this->assertNotNull($visit->started_at);
        $this->assertNull($visit->ended_at);
        $this->assertNull($visit->end_latitude);
        $this->assertNotNull($visit->start_distance);
        $this->assertLessThan(20, (float) $visit->start_distance);
        $this->assertSame('Concrete pour inspection', $visit->purpose);
        $this->assertSame('2026-09-27 11:20:00', $visit->started_at->format('Y-m-d H:i:s'));
    }

    public function test_the_session_decides_who_is_visiting(): void
    {
        $world = $this->world();
        $someoneElse = Employee::factory()->create();

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/site-visits/start', $this->payload($world['site'], [
            'employee_id' => $someoneElse->id,
            'project_id' => null,
            'started_at' => '2020-01-01T00:00:00Z',
            'status' => 'completed',
            'start_distance' => 1,
        ]))->assertCreated();

        $visit = SiteVisit::query()->sole();

        $this->assertSame($world['employee']->id, $visit->employee_id);
        $this->assertSame(SiteVisit::STATUS_OPEN, $visit->status);
        $this->assertSame('2026-09-27 11:20:00', $visit->started_at->format('Y-m-d H:i:s'));
        $this->assertNotSame(1, (int) $visit->start_distance);
    }

    public function test_a_second_visit_cannot_start_while_one_is_open(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/site-visits/start', $this->payload($world['site']))
            ->assertCreated();

        $second = $this->post('/api/v1/site-visits/start', $this->payload($world['site']));

        $second->assertStatus(409);
        $this->assertSame(1, SiteVisit::query()->count());
    }

    public function test_a_visit_outside_the_geofence_is_refused(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/site-visits/start', $this->payload($world['site'], [
            'latitude' => 12.9766,
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('location', $response->json('errors'));
        $this->assertSame(0, SiteVisit::query()->count());
    }

    public function test_a_visit_to_a_site_one_is_not_assigned_to_is_refused(): void
    {
        $world = $this->world();
        EmployeeSiteAssignment::query()->update([
            'status' => EmployeeSiteAssignment::STATUS_ENDED,
            'end_date' => '2026-09-10',
        ]);

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/site-visits/start', $this->payload($world['site']))
            ->assertStatus(403);
    }

    public function test_a_purpose_is_required_because_it_is_the_reason_the_record_exists(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $payload = $this->payload($world['site']);
        unset($payload['purpose']);

        $response = $this->post('/api/v1/site-visits/start', $payload);

        $response->assertStatus(422);
        $this->assertArrayHasKey('purpose', $response->json('errors'));
    }

    public function test_a_replayed_offline_visit_start_returns_the_original_row(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $eventId = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

        $first = $this->post('/api/v1/site-visits/start', $this->payload($world['site'], [
            'client_event_id' => $eventId,
        ]));
        $first->assertCreated();

        $replay = $this->post('/api/v1/site-visits/start', $this->payload($world['site'], [
            'client_event_id' => $eventId,
        ]));
        $replay->assertCreated();

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, SiteVisit::query()->count());
    }

    /* -------------------------------------------------------------- end */

    public function test_ending_a_visit_closes_it_with_an_endpoint_and_a_duration(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $start = $this->post('/api/v1/site-visits/start', $this->payload($world['site']));
        $start->assertCreated();

        $this->travelTo(Carbon::parse('2026-09-27 12:05:00'));

        $end = $this->post('/api/v1/site-visits/'.$start->json('data.id').'/end', [
            'latitude' => 12.9717,
            'longitude' => 77.5946,
            'accuracy' => 5.0,
            'remarks' => 'All good',
        ]);

        $end->assertOk();

        $visit = SiteVisit::query()->sole();

        $this->assertSame(SiteVisit::STATUS_COMPLETED, $visit->status);
        $this->assertSame('2026-09-27 12:05:00', $visit->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame(45, $visit->durationMinutes());
        $this->assertNotNull($visit->end_distance);
        $this->assertSame('All good', $visit->remarks);
    }

    public function test_a_visit_cannot_be_ended_twice(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $start = $this->post('/api/v1/site-visits/start', $this->payload($world['site']));
        $id = $start->json('data.id');

        $this->travelTo(Carbon::parse('2026-09-27 12:05:00'));

        $point = ['latitude' => 12.9717, 'longitude' => 77.5946, 'accuracy' => 5.0];

        $this->post('/api/v1/site-visits/'.$id.'/end', $point)->assertOk();

        $second = $this->post('/api/v1/site-visits/'.$id.'/end', $point);

        $second->assertStatus(409);
        $this->assertSame(1, SiteVisit::query()->whereNotNull('ended_at')->count());
    }

    public function test_a_visit_cannot_be_ended_from_outside_its_geofence(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $id = $this->post('/api/v1/site-visits/start', $this->payload($world['site']))
            ->json('data.id');

        $response = $this->post('/api/v1/site-visits/'.$id.'/end', [
            'latitude' => 12.9766,
            'longitude' => 77.5946,
            'accuracy' => 5.0,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('location', $response->json('errors'));
        $this->assertNull(SiteVisit::query()->sole()->ended_at);
    }

    public function test_nobody_but_the_visitor_may_end_their_visit(): void
    {
        $world = $this->world();

        $other = User::factory()->create();
        $other->assignRole('HR Admin');
        $otherEmployee = Employee::factory()->create(['user_id' => $other->id]);

        Sanctum::actingAs($world['user']);
        $id = $this->post('/api/v1/site-visits/start', $this->payload($world['site']))
            ->json('data.id');

        // A manager with every attendance grant still cannot put an
        // `ended_at` on somebody else's record: the closing act belongs to
        // the person who did the walking.
        Sanctum::actingAs($other);

        $this->post('/api/v1/site-visits/'.$id.'/end', [
            'latitude' => 12.9717,
            'longitude' => 77.5946,
            'accuracy' => 5.0,
        ])->assertStatus(403);

        $this->assertNull(SiteVisit::query()->sole()->ended_at);
        $this->assertNotNull($otherEmployee->id);
    }

    /* ------------------------------------------------------------- today */

    public function test_today_returns_only_the_callers_own_visits(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/site-visits/start', $this->payload($world['site']))
            ->assertCreated();

        $response = $this->getJson('/api/v1/site-visits/today');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));

        $yesterday = SiteVisit::factory()->create([
            'employee_id' => $world['employee']->id,
            'project_id' => $world['project']->id,
            'site_id' => $world['site']->id,
            'started_at' => '2026-09-26 10:00:00',
            'ended_at' => '2026-09-26 11:00:00',
        ]);

        $this->assertCount(1, $this->getJson('/api/v1/site-visits/today')->json('data.items'));
        $this->assertNotNull($yesterday->id);
    }

    public function test_start_requires_a_session(): void
    {
        $world = $this->world();

        $this->post('/api/v1/site-visits/start', $this->payload($world['site']))
            ->assertStatus(401);
    }

    /* ---------------------------------------------------------- listing */

    public function test_the_visit_list_is_behind_attendance_view_and_scoped_by_it(): void
    {
        $world = $this->world();

        $otherProject = Project::factory()->create();
        $otherSite = Site::factory()->create([
            'project_id' => $otherProject->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);
        $otherEmployee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
        ]);
        $otherVisit = SiteVisit::factory()->create([
            'employee_id' => $otherEmployee->id,
            'project_id' => $otherProject->id,
            'site_id' => $otherSite->id,
        ]);

        $mine = SiteVisit::factory()->create([
            'employee_id' => $world['employee']->id,
            'project_id' => $world['project']->id,
            'site_id' => $world['site']->id,
        ]);

        // The ordinary employee sees their own and no one else's.
        Sanctum::actingAs($world['user']);
        $listed = $this->getJson('/api/v1/site-visits')->assertOk()->json('data.items');
        $this->assertSame([$mine->id], array_column($listed, 'id'));

        // A role with no attendance permission never reaches the query.
        $finance = User::factory()->create();
        $finance->assignRole('Finance');
        Sanctum::actingAs($finance);
        $this->getJson('/api/v1/site-visits')->assertStatus(403);

        // HR Admin holds attendance.manage and is not a scoped role, so the
        // whole set is theirs.
        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');
        Sanctum::actingAs($hr);
        $listed = $this->getJson('/api/v1/site-visits')->assertOk()->json('data.items');
        $this->assertCount(2, $listed);

        $this->assertNotNull($otherVisit->id);
    }

    public function test_a_site_supervisor_only_reads_visits_on_the_sites_they_run(): void
    {
        $world = $this->world();

        $supervisorEmployee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
        ]);
        $world['site']->forceFill([
            'site_supervisor_id' => $supervisorEmployee->id,
        ])->save();

        $otherProject = Project::factory()->create();
        $otherSite = Site::factory()->create([
            'project_id' => $otherProject->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);
        $otherEmployee = Employee::factory()->create(['employment_status' => Employee::STATUS_ACTIVE]);
        $elsewhere = SiteVisit::factory()->create([
            'employee_id' => $otherEmployee->id,
            'project_id' => $otherProject->id,
            'site_id' => $otherSite->id,
        ]);

        $onMySite = SiteVisit::factory()->create([
            'employee_id' => $world['employee']->id,
            'project_id' => $world['project']->id,
            'site_id' => $world['site']->id,
        ]);

        $supervisor = User::factory()->create();
        $supervisor->assignRole('Site Supervisor');
        $supervisorEmployee->update(['user_id' => $supervisor->id]);

        Sanctum::actingAs($supervisor);

        $ids = array_column($this->getJson('/api/v1/site-visits')->json('data.items'), 'id');
        $this->assertSame([$onMySite->id], $ids);

        // The row check agrees with the collection: closing a record outside
        // their sites is a 403 — the route for ending a visit carries no
        // coarse gate, so the policy is the only thing standing there.
        $this->post('/api/v1/site-visits/'.$elsewhere->id.'/end', [
            'latitude' => 12.9717,
            'longitude' => 77.5946,
            'accuracy' => 5.0,
        ])->assertStatus(403);
    }
}
