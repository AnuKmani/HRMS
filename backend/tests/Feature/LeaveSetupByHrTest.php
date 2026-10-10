<?php

namespace Tests\Feature;

use App\Models\LeaveBalance;
use App\Models\LeaveType;
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
}
