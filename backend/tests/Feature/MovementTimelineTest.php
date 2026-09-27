<?php

namespace Tests\Feature;

use App\Models\Attendance;
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
 * GET /api/v1/movement/today — the day as one chronological list.
 *
 * The interesting assertions here are about what is *absent*: the timeline
 * is built from four deliberate acts and nothing else. There is no "moved
 * to X" row anywhere, because no position was ever recorded without someone
 * asking for it, and there is no cross-midnight carry-over — a visit
 * started yesterday is a different day's story.
 */
class MovementTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Site $site;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-27 18:30:00'));

        $this->project = Project::factory()->create();

        $this->site = Site::factory()->create([
            'project_id' => $this->project->id,
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'geofence_radius' => 100,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);

        $this->employee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
            'primary_project_id' => null,
            'primary_site_id' => null,
        ]);

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'start_date' => '2026-09-01',
            'end_date' => null,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Employee');
        $this->employee->update(['user_id' => $this->user->id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(): array
    {
        return $this->getJson('/api/v1/movement/today')->assertOk()->json('data.events');
    }

    public function test_the_day_reads_as_a_chronological_sequence_of_four_kinds_of_act(): void
    {
        Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:05:00',
            'check_out_at' => '2026-09-27 18:00:00',
            'status' => Attendance::STATUS_PRESENT,
            'working_minutes' => 505,
        ]);

        SiteVisit::factory()->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'started_at' => '2026-09-27 11:00:00',
            'ended_at' => '2026-09-27 11:45:00',
            'purpose' => 'Concrete pour inspection',
        ]);

        Sanctum::actingAs($this->user);

        $events = $this->events();

        $this->assertSame(
            ['check_in', 'site_visit_start', 'site_visit_end', 'check_out'],
            array_column($events, 'type'),
        );

        $this->assertSame([
            '2026-09-27T09:05:00+00:00',
            '2026-09-27T11:00:00+00:00',
            '2026-09-27T11:45:00+00:00',
            '2026-09-27T18:00:00+00:00',
        ], array_column($events, 'at'));

        // Each kind carries the one fact that makes it worth reading.
        $this->assertSame('Concrete pour inspection', $events[1]['purpose']);
        $this->assertSame(45, $events[2]['duration_minutes']);
        $this->assertSame(505, $events[3]['working_minutes']);

        foreach ($events as $event) {
            $this->assertSame($this->site->id, $event['site_id']);
            $this->assertSame($this->site->name, $event['site_name']);
            $this->assertSame($this->project->id, $event['project_id']);
            $this->assertNotEmpty($event['label']);
        }
    }

    public function test_the_timeline_records_positions_nowhere(): void
    {
        Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:05:00',
            'check_out_at' => null,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        SiteVisit::factory()->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'started_at' => '2026-09-27 11:00:00',
            'ended_at' => null,
            'purpose' => 'Snag list walk',
        ]);

        Sanctum::actingAs($this->user);

        $events = $this->events();

        $this->assertSame(['check_in', 'site_visit_start'], array_column($events, 'type'));

        foreach ($events as $event) {
            foreach (['latitude', 'longitude', 'lat', 'lng', 'accuracy', 'distance', 'path', 'selfie'] as $key) {
                $this->assertArrayNotHasKey($key, $event, "The timeline leaks `{$key}`.");
            }
        }
    }

    public function test_yesterday_is_a_different_story(): void
    {
        SiteVisit::factory()->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'started_at' => '2026-09-26 11:00:00',
            'ended_at' => '2026-09-26 11:30:00',
            'purpose' => 'Yesterday',
        ]);

        Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-26',
            'check_in_at' => '2026-09-26 09:05:00',
            'check_out_at' => '2026-09-26 18:00:00',
            'status' => Attendance::STATUS_PRESENT,
        ]);

        Sanctum::actingAs($this->user);

        $this->assertSame([], $this->events());
    }

    public function test_a_day_with_nothing_happened_is_an_empty_list_not_an_error(): void
    {
        Sanctum::actingAs($this->user);

        $data = $this->getJson('/api/v1/movement/today')->assertOk()->json('data');

        $this->assertSame('2026-09-27', $data['date']);
        $this->assertSame([], $data['events']);
    }

    public function test_the_timeline_is_self_only(): void
    {
        $colleagueUser = User::factory()->create();
        $colleagueUser->assignRole('Employee');
        $colleague = Employee::factory()->create(['employment_status' => Employee::STATUS_ACTIVE]);
        $colleague->update(['user_id' => $colleagueUser->id]);

        Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $colleague->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:05:00',
            'check_out_at' => null,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        // No query parameter widens it: the employee comes from the token.
        Sanctum::actingAs($this->user);

        $this->assertSame([], $this->events());

        $this->assertSame(
            [],
            $this->getJson('/api/v1/movement/today?employee_id='.$colleague->id)
                ->assertOk()
                ->json('data.events'),
        );
    }

    public function test_the_route_needs_a_session(): void
    {
        $this->getJson('/api/v1/movement/today')->assertStatus(401);
    }

    public function test_an_open_day_reports_what_has_happened_so_far(): void
    {
        Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:05:00',
            'check_out_at' => null,
            'status' => Attendance::STATUS_LATE,
            'late_minutes' => 5,
        ]);

        Sanctum::actingAs($this->user);

        $events = $this->events();

        $this->assertSame(['check_in'], array_column($events, 'type'));
        $this->assertSame(Attendance::STATUS_LATE, $events[0]['status']);
        $this->assertArrayNotHasKey('working_minutes', $events[0]);
    }
}
