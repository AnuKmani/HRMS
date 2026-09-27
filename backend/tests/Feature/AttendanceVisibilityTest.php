<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Support\Visibility;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/attendance — who may read whose day.
 *
 * The shape of the rule being enforced here:
 *
 *   - `attendance.view` is the coarse gate on the collection. It is granted
 *     to the ordinary Employee role, so "holding it" proves nothing about
 *     whose rows you get;
 *   - Visibility::mayViewOthersAttendance() decides whether you may read
 *     *somebody else's* at all, and it fails CLOSED — three ways in, no
 *     fourth (an overseer role, `attendance.manage`, or `employees.view`);
 *   - Visibility::attendanceFor() then narrows the query for the field roles
 *     to the projects and sites they actually run;
 *   - AttendancePolicy::view() applies the identical rule to one row, so the
 *     list and the single record can never disagree.
 *
 * Every test below is written to prove the collection AND the row check
 * agree, because a list that leaked while the policy held would be a leak
 * nobody noticed.
 */
class AttendanceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Site $site;

    private Employee $employee;

    private Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-27 09:05:00'));

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

        $this->attendance = Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:00:00',
            'check_out_at' => null,
            'check_in_selfie_path' => 'attendance-selfies/'.$this->employee->id.'/a-selfie.jpg',
            'status' => Attendance::STATUS_PRESENT,
        ]);
    }

    /**
     * A colleague on a different project and site, with their own day.
     *
     * @return array{user: User, employee: Employee, attendance: Attendance}
     */
    private function colleague(): array
    {
        $project = Project::factory()->create();

        $site = Site::factory()->create([
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);

        $employee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
        ]);

        $attendance = Attendance::factory()->at(12.9717, 77.5946, 11)->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:00:00',
            'check_out_at' => null,
            'check_in_selfie_path' => 'attendance-selfies/'.$employee->id.'/colleague.jpg',
            'status' => Attendance::STATUS_PRESENT,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee->update(['user_id' => $user->id]);

        return ['user' => $user, 'employee' => $employee, 'attendance' => $attendance];
    }

    private function selfUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $this->employee->update(['user_id' => $user->id]);

        return $user;
    }

    /* -------------------------------------------------------- the employee */

    public function test_an_employee_lists_only_their_own_attendance(): void
    {
        $colleague = $this->colleague();
        Sanctum::actingAs($this->selfUser());

        $ids = array_column($this->getJson('/api/v1/attendance')->json('data.items'), 'id');

        $this->assertSame([$this->attendance->id], $ids);
        $this->assertNotContains($colleague['attendance']->id, $ids);
    }

    public function test_an_employee_reads_their_own_record_but_not_another(): void
    {
        $colleague = $this->colleague();
        Sanctum::actingAs($this->selfUser());

        $this->getJson('/api/v1/attendance/'.$this->attendance->id)->assertOk();
        $this->getJson('/api/v1/attendance/'.$colleague['attendance']->id)->assertStatus(403);
    }

    public function test_an_employee_cannot_fetch_a_colleagues_selfie(): void
    {
        $colleague = $this->colleague();
        Sanctum::actingAs($this->selfUser());

        $this->getJson('/api/v1/attendance/'.$colleague['attendance']->id.'/selfie')
            ->assertStatus(403);
    }

    public function test_holding_attendance_view_grants_the_own_rows_and_no_others(): void
    {
        $user = $this->selfUser();

        // The grant exists, and it is still not a window onto the workforce.
        $this->assertTrue($user->can('attendance.view'));
        $this->assertTrue($user->can('viewAny', Attendance::class));
        $this->assertFalse(Visibility::mayViewOthersAttendance($user));
    }

    /* ------------------------------------------------------------- HR/admin */

    public function test_hr_admin_reads_every_attendance_record(): void
    {
        $colleague = $this->colleague();

        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');
        Sanctum::actingAs($hr);

        $ids = array_column($this->getJson('/api/v1/attendance')->json('data.items'), 'id');

        $this->assertCount(2, $ids);
        $this->assertContains($this->attendance->id, $ids);
        $this->assertContains($colleague['attendance']->id, $ids);

        // A row check that agrees with the list.
        $this->getJson('/api/v1/attendance/'.$colleague['attendance']->id)->assertOk();
    }

    public function test_hrm_executives_are_scoped_how_the_config_says(): void
    {
        // An HR Executive holds `attendance.view` but is not listed in
        // config('hrms.visibility.attendance'); they qualify instead through
        // `employees.view`, and are then NOT narrowed — the config list is
        // what narrows, and its absence means the whole estate.
        $user = User::factory()->create();
        $user->assignRole('HR Executive');

        $this->assertTrue($user->can('employees.view'));
        $this->assertTrue(Visibility::mayViewOthersAttendance($user));
        $this->assertFalse(Visibility::attendanceIsScopedFor($user));
    }

    /* ------------------------------------------------------ the field roles */

    public function test_a_site_supervisor_reads_only_the_days_on_sites_they_run(): void
    {
        $colleague = $this->colleague();

        $supervisorEmployee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
        ]);
        $this->site->forceFill(['site_supervisor_id' => $supervisorEmployee->id])->save();

        $supervisor = User::factory()->create();
        $supervisor->assignRole('Site Supervisor');
        $supervisorEmployee->update(['user_id' => $supervisor->id]);

        Sanctum::actingAs($supervisor);

        $ids = array_column($this->getJson('/api/v1/attendance')->json('data.items'), 'id');

        $this->assertSame([$this->attendance->id], $ids);
        $this->getJson('/api/v1/attendance/'.$colleague['attendance']->id)->assertStatus(403);
        $this->getJson('/api/v1/attendance/'.$this->attendance->id)->assertOk();
    }

    public function test_a_site_supervisor_with_nothing_to_run_reads_only_their_own_day(): void
    {
        // Listed as an overseer, scoped, and managing nothing: the scope
        // collapses to their own records rather than widening to all of them.
        $colleague = $this->colleague();

        $engineerEmployee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
        ]);
        $engineer = User::factory()->create();
        $engineer->assignRole('Site Engineer');
        $engineerEmployee->update(['user_id' => $engineer->id]);

        Sanctum::actingAs($engineer);

        $ids = array_column($this->getJson('/api/v1/attendance')->json('data.items'), 'id');

        $this->assertSame([], $ids);
        $this->getJson('/api/v1/attendance/'.$colleague['attendance']->id)->assertStatus(403);
    }

    public function test_a_project_manager_reads_the_days_on_the_projects_they_manage(): void
    {
        $colleague = $this->colleague();

        $managerEmployee = Employee::factory()->create([
            'employment_status' => Employee::STATUS_ACTIVE,
        ]);
        $this->project->forceFill(['project_manager_id' => $managerEmployee->id])->save();

        $manager = User::factory()->create();
        $manager->assignRole('Project Manager');
        $managerEmployee->update(['user_id' => $manager->id]);

        Sanctum::actingAs($manager);

        $ids = array_column($this->getJson('/api/v1/attendance')->json('data.items'), 'id');

        $this->assertSame([$this->attendance->id], $ids);
        $this->getJson('/api/v1/attendance/'.$colleague['attendance']->id)->assertStatus(403);
    }

    /* -------------------------------------------------------- the coarse gate */

    public function test_a_role_without_attendance_view_cannot_reach_the_collection(): void
    {
        $finance = User::factory()->create();
        $finance->assignRole('Finance');

        Sanctum::actingAs($finance);

        $this->assertFalse($finance->can('attendance.view'));
        $this->getJson('/api/v1/attendance')->assertStatus(403);
    }

    public function test_attendance_is_unreachable_without_a_session(): void
    {
        $this->getJson('/api/v1/attendance')->assertStatus(401);
        $this->getJson('/api/v1/attendance/'.$this->attendance->id)->assertStatus(401);
    }

    /* --------------------------------------------------------------- today */

    public function test_today_describes_the_callers_own_day_only(): void
    {
        $this->colleague();
        Sanctum::actingAs($this->selfUser());

        $data = $this->getJson('/api/v1/attendance/today')->assertOk()->json('data');

        $this->assertSame($this->employee->id, $data['employee_id']);
        $this->assertSame($this->attendance->id, $data['attendance']['id']);
        $this->assertFalse($data['can_check_in']);
        $this->assertTrue($data['can_check_out']);
        $this->assertNotEmpty($data['sites']);
        // The colleague's day still exists — it is simply not described here.
        $this->assertSame(2, Attendance::query()->count());
    }

    /* -------------------------------------------------------------- filters */

    public function test_the_list_filters_by_site_status_and_date_range(): void
    {
        $colleague = $this->colleague();
        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');
        Sanctum::actingAs($hr);

        $this->assertCount(
            2,
            $this->getJson('/api/v1/attendance?date_from=2026-09-01&date_to=2026-09-30')->json('data.items'),
        );

        $this->assertCount(
            0,
            $this->getJson('/api/v1/attendance?date_from=2026-08-01&date_to=2026-08-31')->json('data.items'),
        );

        $this->assertCount(
            1,
            $this->getJson('/api/v1/attendance?site_id='.$this->site->id)->json('data.items'),
        );

        $this->assertCount(
            1,
            $this->getJson('/api/v1/attendance?employee_id='.$this->employee->id)->json('data.items'),
        );

        $this->assertCount(
            0,
            $this->getJson('/api/v1/attendance?status=missing_checkout')->json('data.items'),
        );

        $this->assertNotNull($colleague['attendance']->id);
    }

    public function test_the_list_is_paginated_with_the_standard_envelope(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');
        Sanctum::actingAs($hr);

        $response = $this->getJson('/api/v1/attendance?per_page=1')->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertArrayHasKey('items', $response->json('data'));
        $this->assertArrayHasKey('meta', $response->json('data'));
        $this->assertSame(1, $response->json('data.meta.per_page'));
        $this->assertSame(1, $response->json('data.meta.current_page'));
    }

    public function test_a_client_supplied_employee_filter_cannot_ask_for_someone_outside_scope(): void
    {
        $colleague = $this->colleague();
        Sanctum::actingAs($this->selfUser());

        // Scoping runs first; the filter narrows what is already theirs and
        // cannot widen it.
        $ids = array_column(
            $this->getJson('/api/v1/attendance?employee_id='.$colleague['employee']->id)->json('data.items'),
            'id',
        );

        $this->assertSame([], $ids);
    }

    public function test_the_response_never_carries_a_storage_path(): void
    {
        Sanctum::actingAs($this->selfUser());

        $item = $this->getJson('/api/v1/attendance/'.$this->attendance->id)
            ->assertOk()
            ->json('data');

        $this->assertTrue($item['has_selfie']);
        $this->assertStringNotContainsString('check_in_selfie_path', json_encode($item));
        $this->assertStringNotContainsString('storage', json_encode($item));
        $this->assertStringNotContainsString('attendance-selfies', json_encode($item));
    }

    public function test_a_persisted_path_that_looks_like_a_traversal_is_never_served(): void
    {
        $this->attendance->forceFill([
            'check_in_selfie_path' => '../../../.env',
        ])->save();

        Sanctum::actingAs($this->selfUser());

        $this->get('/api/v1/attendance/'.$this->attendance->id.'/selfie')
            ->assertStatus(404);
    }

    public function test_a_row_with_no_selfie_says_so_rather_than_failing_silently(): void
    {
        $this->attendance->forceFill(['check_in_selfie_path' => null])->save();

        Sanctum::actingAs($this->selfUser());

        $this->get('/api/v1/attendance/'.$this->attendance->id.'/selfie')
            ->assertStatus(404);
    }

    public function test_the_selfie_route_serves_an_image_with_no_store_headers(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            $this->attendance->check_in_selfie_path,
            base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='),
        );

        Sanctum::actingAs($this->selfUser());

        $response = $this->get('/api/v1/attendance/'.$this->attendance->id.'/selfie');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('image', (string) $response->headers->get('Content-Type'));
    }
}
