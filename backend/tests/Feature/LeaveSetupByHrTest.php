<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * HR sets up leave: unlimited types, days per year that reach everyone,
 * each employee's own figure, and one page showing what everyone has left.
 */
class LeaveSetupByHrTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    public function test_an_unlimited_leave_type_never_runs_out(): void
    {
        LeaveType::query()->where('code', 'SL')->update(['entitlement_days' => 0, 'is_unlimited' => true]);

        $this->signInAs('Employee');
        $draft = $this->draftLeave($this->leaveTypeId('SL'), '2026-10-12', '2026-10-13');

        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->getJson('/api/v1/leave-balances?year=2026')->assertOk();
    }

    public function test_hr_changes_days_per_year_and_it_reaches_everyone_but_hand_set_figures(): void
    {
        [$hr] = $this->makeSeat('HR Admin');
        [, $a] = $this->makeSeat('Employee');
        [, $b] = $this->makeSeat('Employee');
        $type = LeaveType::query()->where('code', 'SL')->firstOrFail();
        $year = (int) now()->year;

        foreach ([$a, $b] as $employee) {
            app(LeaveBalanceService::class)->find($employee, $type, $year);
        }
        LeaveBalance::query()->where('employee_id', $b->id)->update(['entitlement' => 3]);

        $this->become($hr);
        $this->putJson("/api/v1/leave-types/{$type->id}", ['entitlement_days' => 15, 'is_unlimited' => false])
            ->assertOk()
            ->assertJsonPath('data.entitlement_days', 15)
            ->assertJsonPath('data.is_unlimited', false);

        $this->assertSame(15, (int) LeaveBalance::query()->where('employee_id', $a->id)->where('leave_type_id', $type->id)->value('entitlement'));
        $this->assertSame(3, (int) LeaveBalance::query()->where('employee_id', $b->id)->where('leave_type_id', $type->id)->value('entitlement'));
    }

    public function test_hr_sees_everyones_leave_left_and_counts_and_employees_do_not(): void
    {
        [$hr] = $this->makeSeat('HR Admin');
        [$user, $employee] = $this->signInAs('Employee');
        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-12', '2026-10-13');
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();

        $this->getJson('/api/v1/leave-overview')->assertForbidden();

        $this->become($hr);
        $row = collect($this->getJson('/api/v1/leave-overview?year=2026')->assertOk()->json('data.items'))
            ->firstWhere('employee_id', $employee->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['requests']['pending']);
        $annual = collect($row['balances'])->firstWhere('code', 'AL');
        $this->assertEquals(2, $annual['pending']);

        // HR gives this one person extra days.
        $this->putJson("/api/v1/leave-balances/{$annual['id']}", ['adjustment' => 5])->assertOk();
        $this->assertEquals($annual['remaining'] + 5, LeaveBalance::query()->find($annual['id'])->remaining());
    }

    /**
     * Sick leave set to 4 days; Arunlal asks for 6. He can submit; HR
     * decides the extra 2 on the final approval.
     *
     * @return array{0: User, 1: Employee, 2: int}
     */
    private function overBalanceRequest(): array
    {
        LeaveType::query()->where('code', 'SL')->update(['entitlement_days' => 4, 'requires_document' => false, 'maximum_days_per_request' => 0]);
        [$hr] = $this->makeSeat('HR Admin');
        [, $employee] = $this->signInAs('Employee');

        // Mon 2026-10-12 .. Mon 2026-10-19: six working days.
        $draft = $this->draftLeave($this->leaveTypeId('SL'), '2026-10-12', '2026-10-19');
        $leave = $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk()->json('data');

        $this->assertEquals(4, $leave['available_days_at_submit']);
        $this->assertEquals($leave['requested_days'] - 4, $leave['over_balance_days']);

        return [$hr, $employee, $draft['id']];
    }

    private function sickBalance(int $employeeId): LeaveBalance
    {
        return LeaveBalance::query()->with('leaveType')
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $this->leaveTypeId('SL'))
            ->firstOrFail();
    }

    public function test_more_than_the_balance_can_be_submitted_and_hr_gives_the_extra_days(): void
    {
        [$hr, $employee, $id] = $this->overBalanceRequest();

        $this->become($hr);
        // HR must choose.
        $this->postJson("/api/v1/leave/{$id}/approve")->assertUnprocessable()->assertJsonValidationErrors('excess');

        $data = $this->postJson("/api/v1/leave/{$id}/approve", ['excess' => 'grant'])->assertOk()->json('data');
        $this->assertSame('approved', $data['status']);
        $this->assertSame('grant', $data['excess_decision']);
        $this->assertNull($data['lop']);

        $balance = $this->sickBalance($employee->id);
        $this->assertEquals((float) $data['requested_days'], (float) $balance->used);
        $this->assertEquals(0.0, $balance->remaining());
        $this->assertEquals($data['over_balance_days'], (float) $balance->adjustment);
    }

    public function test_hr_makes_the_extra_days_loss_of_pay(): void
    {
        [$hr, $employee, $id] = $this->overBalanceRequest();

        $this->become($hr);
        $data = $this->postJson("/api/v1/leave/{$id}/approve", ['excess' => 'lop'])->assertOk()->json('data');

        $this->assertSame('approved', $data['status']);
        $this->assertSame('lop', $data['excess_decision']);
        $this->assertEquals($data['over_balance_days'], $data['lop']['days']);

        $balance = $this->sickBalance($employee->id);
        $this->assertEquals(4.0, (float) $balance->used);
        $this->assertEquals(0.0, (float) $balance->pending);
        $this->assertEquals(0, (int) $balance->adjustment);
    }
}
