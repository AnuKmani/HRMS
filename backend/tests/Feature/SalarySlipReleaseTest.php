<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\InAppNotification;
use App\Models\Payroll;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * The employee asks for their payslip, HR is told, HR releases it, the
 * employee downloads it. Plus the per-day earnings estimate.
 */
class SalarySlipReleaseTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    /**
     * @return array{0: User, 1: Employee, 2: User, 3: Payroll}
     */
    private function processedSlip(): array
    {
        [$employeeUser, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        [$payrollAdmin] = $this->makeSeat('Payroll Admin', ['salary' => 50000]);

        $this->become($payrollAdmin);
        $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 9])->assertOk();

        $payroll = Payroll::query()->where('employee_id', $employee->id)->sole();
        $payroll->forceFill(['status' => Payroll::STATUS_PROCESSED])->save();

        return [$employeeUser, $employee, $payrollAdmin, $payroll];
    }

    public function test_request_notify_release_download(): void
    {
        [$employeeUser, , $payrollAdmin, $payroll] = $this->processedSlip();

        $this->become($employeeUser);
        $this->getJson('/api/v1/salary-slips')
            ->assertOk()
            ->assertJsonPath('data.items.0.can_download', false);
        $this->get("/api/v1/salary-slips/{$payroll->id}/pdf")->assertForbidden();

        $this->postJson("/api/v1/salary-slips/{$payroll->id}/request")
            ->assertOk()
            ->assertJsonPath('data.slip_request_status', Payroll::SLIP_REQUESTED);

        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $payrollAdmin->id)
                ->where('type', 'payroll.slip_requested')
                ->exists(),
            'Payroll should be told a slip was requested.',
        );

        // Still locked until released.
        $this->get("/api/v1/salary-slips/{$payroll->id}/pdf")->assertForbidden();

        $this->become($payrollAdmin);
        $this->postJson("/api/v1/salary-slips/{$payroll->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.slip_request_status', Payroll::SLIP_APPROVED);

        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $employeeUser->id)
                ->where('type', 'payroll.slip_released')
                ->exists(),
        );

        $this->become($employeeUser);
        $this->get("/api/v1/salary-slips/{$payroll->id}/pdf")->assertOk();
        $this->getJson('/api/v1/salary-slips')->assertJsonPath('data.items.0.can_download', true);
    }

    public function test_a_declined_request_stays_locked(): void
    {
        [$employeeUser, , $payrollAdmin, $payroll] = $this->processedSlip();

        $this->become($employeeUser);
        $this->postJson("/api/v1/salary-slips/{$payroll->id}/request")->assertOk();

        $this->become($payrollAdmin);
        $this->postJson("/api/v1/salary-slips/{$payroll->id}/reject")->assertOk();

        $this->become($employeeUser);
        $this->get("/api/v1/salary-slips/{$payroll->id}/pdf")->assertForbidden();
    }

    public function test_an_employee_cannot_release_their_own_slip(): void
    {
        [$employeeUser, , , $payroll] = $this->processedSlip();

        $this->become($employeeUser);
        $this->postJson("/api/v1/salary-slips/{$payroll->id}/request")->assertOk();
        $this->postJson("/api/v1/salary-slips/{$payroll->id}/approve")->assertForbidden();
    }

    public function test_nobody_can_request_someone_elses_slip(): void
    {
        [, , , $payroll] = $this->processedSlip();
        [$other] = $this->makeSeat('Employee', ['salary' => 30000]);

        $this->become($other);
        $this->postJson("/api/v1/salary-slips/{$payroll->id}/request")->assertForbidden();
    }

    public function test_a_slip_still_being_calculated_cannot_be_requested(): void
    {
        [$employeeUser, , , $payroll] = $this->processedSlip();
        $payroll->forceFill(['status' => Payroll::STATUS_CALCULATED])->save();

        $this->become($employeeUser);
        $this->postJson("/api/v1/salary-slips/{$payroll->id}/request")->assertStatus(409);
    }

    public function test_the_business_can_switch_the_release_step_off(): void
    {
        Setting::query()->where('key', 'payroll.slip_download_requires_approval')->update(['value' => '0']);
        app(SettingsService::class)->refresh();

        [$employeeUser, , , $payroll] = $this->processedSlip();

        $this->become($employeeUser);
        $this->get("/api/v1/salary-slips/{$payroll->id}/pdf")->assertOk();
    }

    public function test_my_earnings_prices_each_day_worked_from_the_salary(): void
    {
        [$user, $employee] = $this->signInAs('Employee', ['salary' => 30000]);

        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $day) {
            Attendance::factory()->create([
                'employee_id' => $employee->id,
                'attendance_date' => $day,
                'check_in_at' => $day.' 09:00:00',
                'check_out_at' => $day.' 18:00:00',
                'status' => Attendance::STATUS_PRESENT,
            ]);
        }

        // Default divisor is a fixed 30: 30000 / 30 = 1000 a day.
        $this->getJson('/api/v1/attendance/my-earnings?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.daily_rate', 1000)
            ->assertJsonPath('data.days_worked', 3)
            ->assertJsonPath('data.total', 3000)
            ->assertJsonPath('data.days.0.date', '2026-09-01');
    }

    public function test_my_earnings_without_a_salary_says_so(): void
    {
        $this->signInAs('Employee', ['salary' => null]);

        $this->getJson('/api/v1/attendance/my-earnings?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }
}
