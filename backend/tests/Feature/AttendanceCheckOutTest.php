<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Shift;
use App\Models\Site;
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
 * POST /api/v1/attendance/check-out
 *
 * Closing the day is where the numbers a payroll run reads are produced, so
 * this file pins each of them: working time, break, lateness, early
 * departure, overtime and the final status — including the two edge cases
 * that break naive implementations, a day that ends at a different site and
 * a shift that runs across midnight.
 */
class AttendanceCheckOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-27 09:00:00'));
    }

    /* ---------------------------------------------------------- fixtures */

    /**
     * @return array{project: Project, site: Site, employee: Employee, user: User}
     */
    private function world(?Shift $shift = null): array
    {
        $project = Project::factory()->create();

        $site = Site::factory()->create([
            'project_id' => $project->id,
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'geofence_radius' => 100,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => $shift?->id,
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
            'assignment_type' => EmployeeSiteAssignment::TYPE_PRIMARY,
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
        ];
    }

    private function checkInAt(array $world, string $at): Attendance
    {
        return Attendance::factory()->open()->create([
            'employee_id' => $world['employee']->id,
            'project_id' => $world['project']->id,
            'site_id' => $world['site']->id,
            'attendance_date' => Carbon::parse($at)->toDateString(),
            'check_in_at' => $at,
            'scheduled_start_at' => Carbon::parse($at)->format('Y-m-d').' 09:00:00',
            'scheduled_end_at' => Carbon::parse($at)->format('Y-m-d').' 18:00:00',
            'check_in_latitude' => 12.9717,
            'check_in_longitude' => 77.5946,
            'status' => Attendance::STATUS_PRESENT,
        ]);
    }

    /* ------------------------------------------------------------- happy */

    public function test_check_out_finalises_the_day_with_derived_figures(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 18:00:00'));

        $response = $this->post('/api/v1/attendance/check-out', $this->payload($world['site']));

        $response->assertOk();

        $attendance = Attendance::query()->sole();

        $this->assertNotNull($attendance->check_out_at);

        // 09:00 -> 18:00 is 540 elapsed, less the 60-minute scheduled break.
        $this->assertSame(480, $attendance->working_minutes);
        $this->assertSame(60, $attendance->break_minutes);
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame(0, $attendance->early_departure_minutes);
        $this->assertSame(0, $attendance->overtime_minutes);
        $this->assertSame(Attendance::STATUS_PRESENT, $attendance->status);
        $this->assertNotNull($attendance->check_out_distance);
    }

    public function test_leaving_early_marks_the_day_incomplete_and_records_how_early(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 17:00:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertOk();

        $attendance = Attendance::query()->sole();

        $this->assertSame(420, $attendance->working_minutes);
        $this->assertSame(60, $attendance->early_departure_minutes);
        $this->assertSame(Attendance::STATUS_INCOMPLETE, $attendance->status);
    }

    public function test_a_late_morning_that_adds_up_to_a_full_day_stays_late_not_incomplete(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:25:00');

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 18:25:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertOk();

        $attendance = Attendance::query()->sole();

        $this->assertSame(25, $attendance->late_minutes);
        $this->assertSame(480, $attendance->working_minutes);
        $this->assertSame(0, $attendance->early_departure_minutes);
        $this->assertSame(Attendance::STATUS_LATE, $attendance->status);
    }

    public function test_staying_on_books_overtime_past_minimum_plus_the_threshold(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 20:00:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertOk();

        $attendance = Attendance::query()->sole();

        // 11h elapsed - 60m break = 600m working. 600 - 480 - 30 = 90.
        $this->assertSame(600, $attendance->working_minutes);
        $this->assertSame(90, $attendance->overtime_minutes);
        $this->assertSame(0, $attendance->early_departure_minutes);
    }

    /* ---------------------------------------------------------- refusals */

    public function test_check_out_without_a_check_in_is_refused(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $response = $this->post('/api/v1/attendance/check-out', $this->payload($world['site']));

        $response->assertStatus(422);
        $this->assertArrayHasKey('check_out', $response->json('errors'));
    }

    public function test_a_second_check_out_is_refused(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 18:00:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertOk();

        $second = $this->post('/api/v1/attendance/check-out', $this->payload($world['site']));

        $second->assertStatus(409);
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(1, Attendance::query()->whereNotNull('check_out_at')->count());
    }

    public function test_checking_out_at_another_site_is_refused(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        $elsewhere = Site::factory()->create([
            'project_id' => $world['project']->id,
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'geofence_radius' => 100,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
            'shift_id' => null,
        ]);

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 18:00:00'));

        $response = $this->post('/api/v1/attendance/check-out', $this->payload($elsewhere));

        $response->assertStatus(422);
        $this->assertArrayHasKey('site_id', $response->json('errors'));
        $this->assertNull(Attendance::query()->sole()->check_out_at);
    }

    public function test_checking_out_outside_the_geofence_is_refused(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 18:00:00'));

        $response = $this->post('/api/v1/attendance/check-out', $this->payload($world['site'], [
            'latitude' => 12.9766,
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('location', $response->json('errors'));
        $this->assertNull(Attendance::query()->sole()->check_out_at);
    }

    public function test_check_out_requires_a_session(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertStatus(401);
    }

    /* ------------------------------------------------------- overnight */

    public function test_an_overnight_shift_is_measured_as_one_window_across_midnight(): void
    {
        $shift = Shift::create([
            'name' => 'Night Crew',
            'code' => 'NGT',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'grace_period' => 10,
            'break_duration' => 30,
            'minimum_working_hours' => 7.50,
            'overtime_threshold' => 0.50,
            'status' => Shift::STATUS_ACTIVE,
        ]);

        $this->assertTrue($shift->crosses_midnight);

        $world = $this->world($shift);

        $attendance = Attendance::factory()->open()->create([
            'employee_id' => $world['employee']->id,
            'project_id' => $world['project']->id,
            'site_id' => $world['site']->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 22:00:00',
            'shift_id' => $shift->id,
            'scheduled_start_at' => '2026-09-27 22:00:00',
            'scheduled_end_at' => '2026-09-28 06:00:00',
            'check_in_latitude' => 12.9717,
            'check_in_longitude' => 77.5946,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-28 06:30:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertOk();

        $attendance->refresh();

        // 22:00 -> 06:30 is 510 minutes across two calendar days, less the
        // 30-minute night break.
        $this->assertSame(480, $attendance->working_minutes);
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame(0, $attendance->early_departure_minutes);
        $this->assertSame(Attendance::STATUS_PRESENT, $attendance->status);
        $this->assertSame('2026-09-27', $attendance->attendance_date->toDateString());
    }

    public function test_the_missing_checkout_flag_is_written_when_the_schedule_ends_unattended(): void
    {
        $world = $this->world();
        $open = $this->checkInAt($world, '2026-09-27 09:00:00');

        // Nobody came back. The next read of "today" is where the rule runs.
        $this->travelTo(Carbon::parse('2026-09-27 19:30:00'));

        Sanctum::actingAs($world['user']);
        $this->getJson('/api/v1/attendance/today')->assertOk();

        $this->assertSame(
            Attendance::STATUS_MISSING_CHECKOUT,
            $open->fresh()->status,
        );

        // Still open, still closable — the flag records the fact, it does
        // not lock anybody out of fixing it.
        $this->travelTo(Carbon::parse('2026-09-27 19:35:00'));
        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertOk();

        $this->assertNotNull($open->fresh()->check_out_at);
    }

    public function test_an_old_open_day_can_still_be_closed(): void
    {
        $world = $this->world();
        $yesterday = $this->checkInAt($world, '2026-09-26 09:00:00');

        Sanctum::actingAs($world['user']);

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))
            ->assertOk();

        $this->assertNotNull($yesterday->fresh()->check_out_at);
        $this->assertSame('2026-09-26', $yesterday->fresh()->attendance_date->toDateString());
    }

    /* -------------------------------------------------------- idempotency */

    public function test_a_replayed_offline_checkout_returns_the_original_row(): void
    {
        $world = $this->world();
        $this->checkInAt($world, '2026-09-27 09:00:00');

        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 18:00:00'));

        $eventId = '8c1b6f6e-0f3f-4a5b-9c8d-7e6f5a4b3c2d';

        $first = $this->post('/api/v1/attendance/check-out', $this->payload($world['site'], [
            'client_event_id' => $eventId,
        ]));

        $first->assertOk();

        $replay = $this->post('/api/v1/attendance/check-out', $this->payload($world['site'], [
            'client_event_id' => $eventId,
        ]));

        $replay->assertOk();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Attendance::query()->count());
    }
}
