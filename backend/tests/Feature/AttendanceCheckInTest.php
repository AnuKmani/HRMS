<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/attendance/check-in
 *
 * The whole point of this file: a check-in is a *claim about the world*
 * that the server either verifies or refuses. Not one field that ends up on
 * the row is taken from the request — the person, the project, the date, the
 * shift, the distance and the status are all derived — and the two gates
 * (are you posted here? are you standing here?) both have to pass.
 *
 * Time is frozen so "was this late?" is a question with one answer rather
 * than whatever the clock happens to say while CI runs.
 */
class AttendanceCheckInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        // 09:05 on a fixed day: five minutes past the 09:00 default start,
        // inside the seeded 10-minute grace, so the expected status is
        // `present` no matter when the suite runs.
        $this->travelTo(Carbon::parse('2026-09-27 09:05:00'));
    }

    /* ---------------------------------------------------------- fixtures */

    /**
     * One project, one geolocated site, one employee posted to it, and the
     * user account behind that employee.
     *
     * @return array{project: Project, site: Site, employee: Employee, user: User}
     */
    private function world(array $siteOverrides = [], array $employeeOverrides = [], bool $withAssignment = true): array
    {
        $project = Project::factory()->create();

        $site = Site::factory()->create($siteOverrides + [
            'project_id' => $project->id,
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'geofence_radius' => 100,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);

        $employee = Employee::factory()->create($employeeOverrides + [
            'employment_status' => Employee::STATUS_ACTIVE,
            'primary_project_id' => null,
            'primary_site_id' => null,
        ]);

        if ($withAssignment) {
            EmployeeSiteAssignment::factory()->create([
                'employee_id' => $employee->id,
                'project_id' => $project->id,
                'site_id' => $site->id,
                'assignment_type' => EmployeeSiteAssignment::TYPE_PRIMARY,
                'start_date' => '2026-09-01',
                'end_date' => null,
                'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
            ]);
        }

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
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 240, 240),
        ];
    }

    /* ------------------------------------------------------------- happy */

    public function test_a_valid_check_in_records_a_derived_attendance_row(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site']));

        $response->assertCreated();

        $attendance = Attendance::query()->sole();

        $this->assertSame($world['employee']->id, $attendance->employee_id);
        $this->assertSame($world['project']->id, $attendance->project_id);
        $this->assertSame($world['site']->id, $attendance->site_id);
        $this->assertSame('2026-09-27', $attendance->attendance_date->toDateString());
        $this->assertNotNull($attendance->check_in_at);
        $this->assertNull($attendance->check_out_at);

        // Derived, not delivered: five minutes past the scheduled start.
        $this->assertSame(5, $attendance->late_minutes);
        $this->assertSame(Attendance::STATUS_PRESENT, $attendance->status);
        $this->assertSame('2026-09-27 09:00:00', $attendance->scheduled_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-27 18:00:00', $attendance->scheduled_end_at->format('Y-m-d H:i:s'));

        // Measured server-side, from the coordinates in this request.
        $this->assertNotNull($attendance->check_in_distance);
        $this->assertLessThan(20, (float) $attendance->check_in_distance);

        $this->assertNotNull($attendance->check_in_selfie_path);
        $this->assertStringNotContainsString('..', $attendance->check_in_selfie_path);
    }

    public function test_the_session_decides_who_is_recorded_not_the_payload(): void
    {
        $world = $this->world();
        $someoneElse = Employee::factory()->create();

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'employee_id' => $someoneElse->id,
            'project_id' => null,
            'attendance_date' => '2020-01-01',
            'check_in_at' => '2020-01-01T00:00:00Z',
            'working_minutes' => 999,
            'late_minutes' => 999,
            'overtime_minutes' => 999,
            'status' => 'manually_adjusted',
            'check_in_distance' => 1,
        ]))->assertCreated();

        $attendance = Attendance::query()->sole();

        $this->assertSame($world['employee']->id, $attendance->employee_id);
        $this->assertNotSame($someoneElse->id, $attendance->employee_id);
        $this->assertSame('2026-09-27', $attendance->attendance_date->toDateString());
        $this->assertSame(0, $attendance->working_minutes);
        $this->assertSame(5, $attendance->late_minutes);
        $this->assertSame(0, $attendance->overtime_minutes);
        $this->assertSame(Attendance::STATUS_PRESENT, $attendance->status);
        $this->assertNotSame(1, (int) $attendance->check_in_distance);
    }

    public function test_check_in_is_not_a_permission_anybody_grants(): void
    {
        // The ordinary Employee role holds `attendance.view` and nothing
        // else that mentions attendance — yet may still clock in.
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $this->assertTrue($world['user']->can('attendance.view'));
        $this->assertFalse($world['user']->can('attendance.manage'));

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertCreated();
    }

    /* --------------------------------------------------------- geofence */

    public function test_a_point_outside_the_geofence_is_rejected(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        // ~556 m north — well past a 100 m fence.
        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'latitude' => 12.9766,
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertArrayHasKey('location', $response->json('errors'));
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_an_out_of_range_coordinate_is_rejected_as_a_field_error(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'latitude' => 129.7,
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('latitude', $response->json('errors'));
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_a_fix_that_does_not_know_where_it_is_is_rejected(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'latitude' => 0,
            'longitude' => 0,
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('location', $response->json('errors'));
    }

    public function test_a_gps_reading_worse_than_the_configured_ceiling_is_rejected(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $ceiling = (float) config('hrms.attendance.max_gps_accuracy_metres');

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'accuracy' => $ceiling + 250,
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('accuracy', $response->json('errors.location.0'));
    }

    public function test_a_site_with_no_location_cannot_be_checked_in_at(): void
    {
        $world = $this->world(['latitude' => null, 'longitude' => null, 'geofence_radius' => null]);
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site']));

        $response->assertStatus(422);
        $this->assertStringContainsString('no location', $response->json('errors.location.0'));
    }

    /* ------------------------------------------------------ authorisation */

    public function test_someone_with_no_assignment_to_the_site_is_refused(): void
    {
        $world = $this->world(withAssignment: false);
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site']));

        $response->assertStatus(403);
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_an_ended_assignment_does_not_authorise_a_check_in(): void
    {
        $world = $this->world();
        EmployeeSiteAssignment::query()->update([
            'status' => EmployeeSiteAssignment::STATUS_ENDED,
            'end_date' => '2026-09-10',
        ]);

        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site']));

        $response->assertStatus(403);
        $this->assertStringContainsString('ended on 2026-09-10', $response->json('message'));
    }

    public function test_an_assignment_that_has_not_started_yet_is_refused(): void
    {
        $world = $this->world();
        EmployeeSiteAssignment::query()->update(['start_date' => '2026-10-01']);

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertStatus(403);
    }

    public function test_a_temporary_assignment_authorises_a_check_in(): void
    {
        $world = $this->world();
        EmployeeSiteAssignment::query()->update(['assignment_type' => EmployeeSiteAssignment::TYPE_TEMPORARY]);

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertCreated();
    }

    public function test_an_additional_assignment_authorises_a_check_in(): void
    {
        $world = $this->world();
        EmployeeSiteAssignment::query()->update(['assignment_type' => EmployeeSiteAssignment::TYPE_ADDITIONAL]);

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertCreated();
    }

    public function test_the_profile_primary_site_authorises_a_check_in_when_no_rows_exist(): void
    {
        $world = $this->world(withAssignment: false);
        $world['employee']->update([
            'primary_project_id' => $world['project']->id,
            'primary_site_id' => $world['site']->id,
        ]);

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertCreated();
    }

    public function test_a_closed_assignment_row_beats_the_profile_fallback(): void
    {
        // The posting was explicitly ended. Falling back to the profile's
        // primary site here would silently overrule a decision somebody
        // already made.
        $world = $this->world();
        $world['employee']->update([
            'primary_project_id' => $world['project']->id,
            'primary_site_id' => $world['site']->id,
        ]);
        EmployeeSiteAssignment::query()->update([
            'status' => EmployeeSiteAssignment::STATUS_ENDED,
            'end_date' => '2026-09-10',
        ]);

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertStatus(403);
    }

    public function test_an_assignment_pointing_at_another_projects_site_is_refused(): void
    {
        $world = $this->world();
        EmployeeSiteAssignment::query()->update(['project_id' => Project::factory()->create()->id]);

        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site']));

        $response->assertStatus(403);
        $this->assertStringContainsString('different project', $response->json('message'));
    }

    public function test_an_inactive_site_refuses_check_in(): void
    {
        $world = $this->world(['status' => Site::STATUS_INACTIVE]);
        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertStatus(403);
    }

    public function test_an_inactive_employee_refuses_check_in(): void
    {
        $world = $this->world(employeeOverrides: ['employment_status' => Employee::STATUS_RESIGNED]);
        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertStatus(403);
    }

    /* ------------------------------------------------------- duplicates */

    public function test_checking_in_twice_on_the_same_day_is_refused(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertCreated();

        $second = $this->post('/api/v1/attendance/check-in', $this->payload($world['site']));

        $second->assertStatus(409);
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_an_unclosed_yesterday_blocks_a_new_check_in(): void
    {
        $world = $this->world();
        Attendance::factory()->open()->create([
            'employee_id' => $world['employee']->id,
            'project_id' => $world['project']->id,
            'site_id' => $world['site']->id,
            'attendance_date' => '2026-09-26',
            'check_in_at' => '2026-09-26 09:00:00',
            'scheduled_end_at' => '2026-09-26 18:00:00',
        ]);

        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site']));

        $response->assertStatus(409);
        $this->assertStringContainsString('have not checked out', $response->json('message'));
    }

    public function test_a_replayed_offline_event_returns_the_original_row_not_a_second_one(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $eventId = '3f0f3d2e-5a4a-4f4f-9a9a-1b2c3d4e5f60';

        $first = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'client_event_id' => $eventId,
            'source' => 'offline',
        ]));

        $first->assertCreated();

        // The response was lost, the device retried: same key, same row.
        $replay = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'client_event_id' => $eventId,
            'source' => 'offline',
        ]));

        $replay->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(Attendance::SOURCE_OFFLINE, Attendance::query()->sole()->source);
    }

    /* ----------------------------------------------------------- selfie */

    public function test_a_selfie_is_required(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $payload = $this->payload($world['site']);
        unset($payload['selfie']);

        $response = $this->post('/api/v1/attendance/check-in', $payload);

        $response->assertStatus(422);
        $this->assertArrayHasKey('selfie', $response->json('errors'));
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'selfie' => UploadedFile::fake()->create('report.pdf', 12, 'application/pdf'),
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('selfie', $response->json('errors'));
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_an_oversized_selfie_is_refused(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $tooBig = (int) config('hrms.storage.selfie_max_kilobytes') + 100;

        $response = $this->post('/api/v1/attendance/check-in', $this->payload($world['site'], [
            'selfie' => UploadedFile::fake()->create('selfie.jpg', $tooBig, 'image/jpeg'),
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('selfie', $response->json('errors'));
    }

    /* --------------------------------------------------------- transport */

    public function test_check_in_requires_a_session(): void
    {
        $world = $this->world();

        $this->post('/api/v1/attendance/check-in', $this->payload($world['site']))
            ->assertStatus(401);
    }

    public function test_the_attendance_write_throttle_is_configured_rather_than_inlined(): void
    {
        $this->assertArrayHasKey('attendance', config('rate_limiting'));
        $this->assertGreaterThan(0, config('rate_limiting.attendance.max_attempts'));
        $this->assertGreaterThan(0, config('rate_limiting.attendance.decay_minutes'));
    }

    public function test_an_account_with_no_employee_record_cannot_check_in(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Sanctum::actingAs($user);

        $site = Site::factory()->create(['site_manager_id' => null, 'site_supervisor_id' => null]);

        $this->post('/api/v1/attendance/check-in', $this->payload($site))
            ->assertStatus(403);
    }
}
