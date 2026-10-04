<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A check-out that earns overtime raises the overtime claim itself.
 *
 * The employee files nothing: the minutes are the attendance row's own,
 * derived at check-out from the shift's configurable threshold, and the
 * claim enters the configured approval workflow as pending. Payroll still
 * reads only approved claims — nothing here pays anybody.
 */
class AutoOvertimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(ApprovalWorkflowSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-27 09:00:00'));
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
            // The organisation default schedule (09:00-18:00, 8h, 30m
            // threshold) rather than whatever shift a factory might pick.
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
            'assignment_type' => EmployeeSiteAssignment::TYPE_PRIMARY,
            'start_date' => '2026-09-01',
            'end_date' => null,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee->update(['user_id' => $user->id]);

        Attendance::factory()->open()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'attendance_date' => '2026-09-27',
            'check_in_at' => '2026-09-27 09:00:00',
            'scheduled_start_at' => '2026-09-27 09:00:00',
            'scheduled_end_at' => '2026-09-27 18:00:00',
            'check_in_latitude' => 12.9717,
            'check_in_longitude' => 77.5946,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        return compact('project', 'site', 'employee', 'user');
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

    public function test_a_late_check_out_raises_a_pending_overtime_claim(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 20:00:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))->assertOk();

        $attendance = Attendance::query()->sole();
        $claim = OvertimeRequest::query()->sole();

        // 11h elapsed - 60m break = 600m; 600 - 480 minimum - 30 threshold.
        $this->assertSame(90, $attendance->overtime_minutes);
        $this->assertSame(90, $claim->requested_minutes);
        $this->assertSame($attendance->id, $claim->attendance_id);
        $this->assertSame($world['employee']->id, $claim->employee_id);
        $this->assertSame($world['site']->id, $claim->site_id);
        $this->assertSame('2026-09-27', $claim->overtime_date->toDateString());
        $this->assertSame(OvertimeRequest::STATUS_PENDING, $claim->status);
        $this->assertNotNull($claim->submitted_at);
        $this->assertNull($claim->approved_minutes);
        $this->assertStringContainsString('1h 30m', $claim->reason);
    }

    public function test_the_employee_sees_the_claim_in_their_own_overtime_list(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 20:00:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))->assertOk();

        $this->getJson('/api/v1/overtime')
            ->assertOk()
            ->assertJsonPath('data.items.0.requested_minutes', 90)
            ->assertJsonPath('data.items.0.status', OvertimeRequest::STATUS_PENDING);
    }

    public function test_a_normal_day_raises_nothing(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 18:00:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))->assertOk();

        $this->assertSame(0, OvertimeRequest::query()->count());
    }

    public function test_a_replayed_check_out_does_not_raise_a_second_claim(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 20:00:00'));

        $event = ['client_event_id' => '8c1b6f6e-0f3f-4a5b-9c8d-7e6f5a4b3c2d'];

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site'], $event))->assertOk();
        $this->post('/api/v1/attendance/check-out', $this->payload($world['site'], $event))->assertOk();

        $this->assertSame(1, OvertimeRequest::query()->count());
    }

    public function test_the_business_can_switch_it_off(): void
    {
        Setting::query()
            ->where('key', 'overtime.auto_request_from_attendance')
            ->update(['value' => '0']);
        app(SettingsService::class)->refresh();

        $world = $this->world();
        Sanctum::actingAs($world['user']);
        $this->travelTo(Carbon::parse('2026-09-27 20:00:00'));

        $this->post('/api/v1/attendance/check-out', $this->payload($world['site']))->assertOk();

        $this->assertSame(90, Attendance::query()->sole()->overtime_minutes);
        $this->assertSame(0, OvertimeRequest::query()->count());
    }

    public function test_an_employee_can_read_overtime_but_not_file_it_by_hand(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $this->getJson('/api/v1/overtime')->assertOk();

        $this->postJson('/api/v1/overtime', [
            'overtime_date' => '2026-09-26',
            'requested_minutes' => 60,
            'reason' => 'Stayed late',
        ])->assertForbidden();
    }

    public function test_the_employee_role_no_longer_opens_people_payroll_or_expenses(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['user']);

        $this->getJson('/api/v1/employee-documents')->assertForbidden();
        $this->getJson('/api/v1/employee-training')->assertForbidden();
        $this->getJson('/api/v1/onboarding')->assertForbidden();
        $this->getJson('/api/v1/payroll')->assertForbidden();
        $this->getJson('/api/v1/expenses')->assertForbidden();

        // What stays: their own payslips.
        $this->getJson('/api/v1/salary-slips')->assertOk();
    }
}
