<?php

namespace Tests\Feature;

use App\Models\Allowance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * POST/GET /api/v1/payroll and the five buttons that move a row along.
 *
 * The file is arranged around the four things that make payroll different
 * from every other module in this application:
 *
 *  - **it only ever reads.** Nothing here invents an absence, a rate or a
 *    status. Attendance, leave, overtime and loans have already decided
 *    what they mean; this module prices those decisions and nothing else,
 *    so the tests assert against figures derived from the sources rather
 *    than from a second opinion about them.
 *
 *  - **the arithmetic has one identity.** gross - deductions = net, and
 *    every item on the row adds back to the totals beside it. A test that
 *    proves the formula and a test that proves the invariant are two
 *    different tests, so there are two.
 *
 *  - **recalculation is a one-way door.** `calculated` may be restated;
 *    `reviewed`, `processed` and `locked` may not, and neither may a second
 *    run of the same month.
 *
 *  - **visibility is permission-shaped, not role-shaped.** Employee reads
 *    one row; Payroll Admin reads the building; Management reads the
 *    totals and one row; the site roles read nothing at all.
 *
 * Every figure below is derived from `salary = 30000` and the seeded
 * settings (divisor 30, daily hours 8, overtime multiplier 1.5), so a day
 * is 1,000.00, an hour of overtime is 187.50, and nothing depends on
 * rounding at any other place than the one `Money` owns.
 */
class PayrollTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    private const YEAR = 2026;

    private const MONTH = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    /* ------------------------------------------------------------ the run */

    public function test_a_run_prices_a_month_from_sources_it_never_writes(): void
    {
        [$owner, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        // Everything the calculation reads, set the way the *other* module
        // would have left it: an allowance nobody signed off, a bonus
        // somebody did, an unpaid day Phase 6 converted to loss of pay, and
        // overtime the chain approved and marked payable.
        Allowance::create([
            'employee_id' => $employee->id,
            'code' => 'housing',
            'label' => 'Housing allowance',
            'amount' => 3000,
            'frequency' => Allowance::FREQUENCY_MONTHLY,
            'effective_from' => '2026-01-01',
            'created_by' => $owner->id,
        ]);

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'payroll_year' => self::YEAR,
            'payroll_month' => self::MONTH,
            'type' => PayrollAdjustment::TYPE_BONUS,
            'description' => 'Festival bonus',
            'amount' => 1000,
            'status' => PayrollAdjustment::STATUS_APPROVED,
            'created_by' => $owner->id,
        ]);

        $this->lopLeave($employee, '2026-09-07', '2026-09-08', 2);

        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'overtime_date' => '2026-09-15',
            'requested_minutes' => 60,
            'approved_minutes' => 60,
            'reason' => 'Handover ran long.',
            'status' => OvertimeRequest::STATUS_APPROVED,
            'approved_at' => now(),
            'payroll_eligible' => true,
        ]);

        $report = $this->process();

        $this->assertSame(0, $report['draft']);
        $this->assertGreaterThanOrEqual(1, $report['calculated']);

        $row = $this->row($employee->id);

        // Divisor 30, so a lost day is a thirtieth of basic: 1,000.00.
        // The hourly rate is 30000 / 30 / 8 = 125.00, times 1.5 =
        // 187.50, and one hour was claimed.
        $this->assertSame(30000.0, (float) $row['basic_salary']);
        $this->assertSame(3000.0, (float) $row['total_allowances']);
        $this->assertSame(187.5, (float) $row['overtime_amount']);
        $this->assertSame(60, (int) $row['overtime_minutes']);
        $this->assertSame(1000.0, (float) $row['bonus_amount']);
        $this->assertSame(34187.5, (float) $row['gross_salary']);

        $this->assertSame(2.0, (float) $row['lop_days']);
        $this->assertSame(30.0, (float) $row['lop_divisor']);
        $this->assertSame(2000.0, (float) $row['lop_amount']);
        $this->assertSame(2000.0, (float) $row['total_deductions']);
        $this->assertSame(32187.5, (float) $row['net_salary']);

        // The identity, asserted rather than assumed.
        $this->assertSame(
            Money::round($row['gross_salary'] - $row['total_deductions']),
            Money::round($row['net_salary']),
        );

        // And the itemisation adds back to both totals - the invariant that
        // makes a payslip explainable line by line.
        $items = PayrollItem::query()->where('payroll_id', $row['id'])->get();
        $this->assertCount(5, $items);
        $this->assertSame(
            34187.5,
            Money::sum($items->where('type', 'earning')->pluck('amount')->all()),
        );
        $this->assertSame(
            2000.0,
            Money::sum($items->where('type', 'deduction')->pluck('amount')->all()),
        );
    }

    public function test_overtime_pays_only_when_both_the_approval_and_the_flag_say_so(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        // Four claims on four days: signed and payable, signed but marked
        // not payable, waiting, and refused. Only the first may reach pay.
        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'overtime_date' => '2026-09-02',
            'requested_minutes' => 60,
            'approved_minutes' => 60,
            'reason' => 'Paid.',
            'status' => OvertimeRequest::STATUS_APPROVED,
            'payroll_eligible' => true,
        ]);
        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'overtime_date' => '2026-09-03',
            'requested_minutes' => 600,
            'approved_minutes' => 600,
            'reason' => 'Signed off, then held back from the pay run.',
            'status' => OvertimeRequest::STATUS_APPROVED,
            'payroll_eligible' => false,
        ]);
        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'overtime_date' => '2026-09-04',
            'requested_minutes' => 600,
            'reason' => 'Still waiting.',
            'status' => OvertimeRequest::STATUS_PENDING,
            'payroll_eligible' => true,
        ]);
        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'overtime_date' => '2026-09-05',
            'requested_minutes' => 600,
            'approved_minutes' => 0,
            'reason' => 'Covered by the shift already paid.',
            'status' => OvertimeRequest::STATUS_REJECTED,
            'payroll_eligible' => false,
        ]);

        $this->process();

        $row = $this->row($employee->id);

        $this->assertSame(60, (int) $row['overtime_minutes'], 'Only the payable claim counted.');
        $this->assertSame(187.5, (float) $row['overtime_amount']);
        $this->assertSame(30187.5, (float) $row['net_salary']);
    }

    public function test_only_approved_adjustments_are_paid_and_each_one_carries_its_own_sign(): void
    {
        [$owner, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        foreach ([
            [PayrollAdjustment::STATUS_PENDING, 'Awaiting a decision', 2000],
            [PayrollAdjustment::STATUS_REJECTED, 'Refused', 3000],
            [PayrollAdjustment::STATUS_CANCELLED, 'Withdrawn', 4000],
        ] as [$status, $description, $amount]) {
            PayrollAdjustment::create([
                'employee_id' => $employee->id,
                'payroll_year' => self::YEAR,
                'payroll_month' => self::MONTH,
                'type' => PayrollAdjustment::TYPE_BONUS,
                'description' => $description,
                'amount' => $amount,
                'status' => $status,
                'created_by' => $owner->id,
            ]);
        }

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'payroll_year' => self::YEAR,
            'payroll_month' => self::MONTH,
            'type' => PayrollAdjustment::TYPE_OTHER_DEDUCTION,
            'description' => 'Canteen recovery',
            'amount' => 500,
            'status' => PayrollAdjustment::STATUS_APPROVED,
            'created_by' => $owner->id,
        ]);

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'payroll_year' => self::YEAR,
            'payroll_month' => self::MONTH + 1,
            'type' => PayrollAdjustment::TYPE_BONUS,
            'description' => 'Wrong month',
            'amount' => 9000,
            'status' => PayrollAdjustment::STATUS_APPROVED,
            'created_by' => $owner->id,
        ]);

        $this->process();

        $row = $this->row($employee->id);

        $this->assertSame(0.0, (float) $row['bonus_amount'], 'Nothing signed off stays unpaid; nothing else is paid.');
        $this->assertSame(500.0, (float) $row['other_deductions']);
        $this->assertSame(500.0, (float) $row['total_deductions']);
        $this->assertSame(29500.0, (float) $row['net_salary']);

        // Stored as a positive figure with the *type* supplying the minus -
        // no negative column anywhere for a downstream reader to get wrong.
        $canteen = PayrollItem::query()
            ->where('payroll_id', $row['id'])
            ->where('code', PayrollItem::CODE_OTHER)
            ->firstOrFail();
        $this->assertSame(500.0, (float) $canteen->amount);
    }

    public function test_the_divisor_is_a_setting_and_not_a_constant(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $this->lopLeave($employee, '2026-09-07', '2026-09-08', 2);

        $this->process();

        $row = $this->row($employee->id);
        $this->assertSame(30.0, (float) $row['lop_divisor']);
        $this->assertSame(2000.0, (float) $row['lop_amount']);

        // Retune the divisor and re-run: the same two days now cost a
        // different amount, which is the entire point of the setting
        // existing rather than being a 30 in somebody's code.
        Setting::query()->where('key', 'payroll.lop_divisor')->update(['value' => '60']);
        app(SettingsService::class)->refresh();

        $this->process();

        $row = $this->row($employee->id);
        $this->assertSame(60.0, (float) $row['lop_divisor']);
        $this->assertSame(1000.0, (float) $row['lop_amount']);
        $this->assertSame(29000.0, (float) $row['net_salary']);
    }

    public function test_an_employee_with_no_salary_is_a_visible_draft_not_a_missing_row(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => null]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $report = $this->process();

        $this->assertSame(1, $report['draft']);

        $row = $this->row($employee->id);
        $this->assertSame(Payroll::STATUS_DRAFT, $row['status']);
        $this->assertNotNull($row['blocked_reason']);
        $this->assertSame(0.0, (float) $row['net_salary']);
    }

    /* ------------------------------------------------------- the one door */

    public function test_a_row_walks_the_flow_one_way_and_then_freezes(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $this->process();
        $id = $this->row($employee->id)['id'];

        // calculated -> reviewed -> processed -> locked, and each step
        // refuses to be skipped or repeated.
        $this->postJson("/api/v1/payroll/{$id}/lock")->assertStatus(409);
        $this->postJson("/api/v1/payroll/{$id}/finalize")->assertStatus(409);

        $this->postJson("/api/v1/payroll/{$id}/review")
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewed');
        $this->postJson("/api/v1/payroll/{$id}/review")->assertStatus(409);

        $this->postJson("/api/v1/payroll/{$id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->postJson("/api/v1/payroll/{$id}/lock")
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        // Locked is the end of the road: no unlock exists anywhere, so the
        // last two verbs simply do not apply any more.
        $this->postJson("/api/v1/payroll/{$id}/lock")->assertStatus(409);
        $this->postJson("/api/v1/payroll/{$id}/review")->assertStatus(409);
        $this->postJson("/api/v1/payroll/{$id}/recalculate")->assertStatus(409);

        $locked = $this->row($employee->id);
        $this->assertNotNull($locked['locked_at']);
        $this->assertSame(30000.0, (float) $locked['net_salary']);
    }

    public function test_a_second_run_never_rewrites_a_row_that_has_been_decided(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $this->process();
        $id = $this->row($employee->id)['id'];
        $this->postJson("/api/v1/payroll/{$id}/review")->assertOk();

        // A change that would alter the figure, and a second run over the
        // whole month. The row is `reviewed`, so it is counted as skipped
        // rather than silently restated under an approver's signature.
        Setting::query()->where('key', 'payroll.lop_divisor')->update(['value' => '60']);
        app(SettingsService::class)->refresh();

        $report = $this->process();

        // Two people were in the run and only one of them was reviewed, so
        // the counts are read as a *contrast* rather than as two zeroes:
        // the reviewed row is skipped, the colleague beside it is not
        // frozen with them, and the setting change lands on exactly one
        // of the two.
        $this->assertSame(1, $report['skipped'], 'The reviewed row is left exactly as it was signed off.');
        $this->assertSame(1, $report['calculated'], 'The unreviewed colleague beside it still moves.');
        $this->assertSame(1, $report['updated']);

        $mine = $this->row($employee->id);
        $this->assertSame(30.0, (float) $mine['lop_divisor']);

        $theirs = Payroll::query()
            ->where('payroll_year', self::YEAR)
            ->where('payroll_month', self::MONTH)
            ->where('employee_id', '!=', $employee->id)
            ->firstOrFail();

        $this->assertSame(60.0, (float) $theirs->lop_divisor, 'The colleague who was never reviewed did move.');
    }

    public function test_a_recalculation_gives_a_loan_installment_back_before_it_takes_it_again(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->activeLoan($employee, 12000, 12, '2026-09-05');

        $this->process();

        $row = $this->row($employee->id);
        $this->assertSame(1000.0, (float) $row['loan_deduction']);
        $this->assertSame(29000.0, (float) $row['net_salary']);
        $this->assertSame(11000.0, (float) $loan->fresh()->outstanding_balance);

        // The row is still `calculated`, so it may be re-run - and the
        // installment it already holds has to come back first, or the
        // second run would find nothing pending and pay the deduction as
        // zero. A recalculation that *removes* money is the bug this order
        // exists to prevent.
        $this->postJson("/api/v1/payroll/{$row['id']}/recalculate")
            ->assertOk()
            ->assertJsonPath('data.status', 'calculated');

        $again = $this->row($employee->id);
        $this->assertSame(1000.0, (float) $again['loan_deduction'], 'Taken exactly once, not twice and not zero.');
        $this->assertSame(29000.0, (float) $again['net_salary']);
        $this->assertSame(11000.0, (float) $loan->fresh()->outstanding_balance);

        $this->assertSame(1, $loan->installments()->where('status', 'deducted')->count());
    }

    /* ------------------------------------------------------- the aggregate */

    public function test_the_summary_is_aggregates_only_and_reaches_a_role_that_cannot_read_the_list(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        // Management holds `payroll.summary.view` and `payroll.view`, but
        // neither `payroll.manage` nor `employees.salary.view` - so the
        // list will narrow to their own row while the totals still arrive.
        [$manager, $managerEmployee] = $this->makeSeat('Management', ['salary' => 99999]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $this->process();

        $this->become($manager);

        $summary = $this->getJson('/api/v1/payroll/summary?year=2026&month=9')
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $summary['employee_count']);
        $this->assertSame(179999.0, (float) $summary['gross_payroll']);
        $this->assertSame('INR', $summary['currency']);

        // No names, no ids, no per-person figures anywhere in the payload -
        // the shape is what keeps a summary-only grant summary-only.
        $this->assertStringNotContainsString('employee_id', json_encode($summary));

        $items = $this->getJson('/api/v1/payroll?year=2026&month=9')
            ->assertOk()
            ->json('data.items');

        $this->assertSame(
            [$managerEmployee->id],
            array_column($items, 'employee_id'),
            'The summary grant never leaked into the list.',
        );

        // And a role with neither grant cannot reach the aggregate either.
        $this->signInAs('Site Engineer', ['salary' => 30000]);
        $this->getJson('/api/v1/payroll/summary?year=2026')->assertForbidden();
    }

    /* -------------------------------------------------------- visibility */

    public function test_an_employee_reads_exactly_one_row_and_never_the_others(): void
    {
        [$mineUser, $mine] = $this->makeSeat('Employee', ['salary' => 30000]);
        [, $theirs] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $this->process();

        $theirId = $this->row($theirs->id)['id'];

        $this->become($mineUser);

        $items = $this->getJson('/api/v1/payroll?year=2026&month=9')
            ->assertOk()
            ->json('data.items');

        $this->assertSame(
            [$mine->id],
            array_column($items, 'employee_id'),
            'The permission that reads your own pay does not publish the company.',
        );

        // Asking for a colleague by id is refused - the coarse gate is
        // open, and the row question is the policy's.
        $this->getJson("/api/v1/payroll/{$theirId}")->assertForbidden();
    }

    public function test_site_roles_have_no_payroll_visibility_at_all(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);
        $this->process();

        $id = $this->row($employee->id)['id'];

        // Project Manager and Site Supervisor are the two the spec singles
        // out: they run sites, not salaries, and nothing about payroll is
        // implied by either.
        foreach (['Project Manager', 'Site Supervisor'] as $role) {
            $this->signInAs($role, ['salary' => 30000]);

            $this->getJson('/api/v1/payroll?year=2026')->assertForbidden();
            $this->getJson("/api/v1/payroll/{$id}")->assertForbidden();
            $this->getJson('/api/v1/payroll/summary?year=2026')->assertForbidden();
            $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 9])->assertForbidden();
        }
    }

    public function test_running_a_month_needs_the_process_grant_not_just_the_permission_to_read(): void
    {
        $this->signInAs('Payroll Admin', ['salary' => 30000]);

        $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 9])
            ->assertOk()
            ->assertJsonPath('data.year', 2026);

        // HR Admin may correct and review, and holds `payroll.process` too
        // - but not `payroll.lock`, which is the one grant Payroll Admin
        // keeps for itself.
        $this->signInAs('HR Admin', ['salary' => 30000]);
        $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 9])->assertOk();

        [, $model] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->process();

        $row = Payroll::query()->where('employee_id', $model->id)->firstOrFail();

        // ... and a lock is refused for the role that may run everything
        // else. The narrowest grant in the system, on purpose.
        $this->postJson("/api/v1/payroll/{$row->id}/lock")->assertForbidden();

        $this->signInAs('Employee', ['salary' => 30000]);
        $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 9])
            ->assertForbidden();
    }

    public function test_a_period_outside_the_window_is_refused_before_it_reaches_the_calculator(): void
    {
        $this->signInAs('Payroll Admin', ['salary' => 30000]);

        $this->postJson('/api/v1/payroll/process', ['year' => 1999, 'month' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year']);

        $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);

        $this->postJson('/api/v1/payroll/process', ['year' => 2026])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);

        $this->assertSame(0, Payroll::query()->count());
    }

    /* ----------------------------------------------------------- helpers */

    private function process(int $year = self::YEAR, int $month = self::MONTH): array
    {
        return $this->postJson('/api/v1/payroll/process', ['year' => $year, 'month' => $month])
            ->assertOk()
            ->json('data');
    }

    /**
     * The row as the API renders it, so assertions are on what a screen
     * would actually be handed.
     *
     * @return array<string, mixed>
     */
    private function row(int $employeeId): array
    {
        $items = $this->getJson(
            sprintf('/api/v1/payroll?year=%d&month=%d&employee_id=%d', self::YEAR, self::MONTH, $employeeId),
        )->assertOk()->json('data.items');

        $this->assertNotEmpty($items, "No payroll row for employee {$employeeId}.");

        return $items[0];
    }

    /**
     * A leave request Phase 6 would have converted to loss of pay, written
     * the way the conversion leaves it.
     */
    private function lopLeave(Employee $employee, string $from, string $to, float $days): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'AL')->value('id'),
            'start_date' => $from,
            'end_date' => $to,
            'requested_days' => $days,
            'status' => LeaveRequest::STATUS_LOP,
            'lop_days' => $days,
            'approved_at' => now(),
        ]);
    }

    /**
     * An approved loan whose repayment is open - the state `LoanService`
     * leaves it in once a decision has been made.
     */
    private function activeLoan(Employee $employee, float $principal, int $count, string $start): Loan
    {
        $loan = Loan::create([
            'employee_id' => $employee->id,
            'loan_type' => Loan::TYPE_LOAN,
            'principal_amount' => $principal,
            'installment_amount' => Money::round($principal / $count),
            'number_of_installments' => $count,
            'start_date' => $start,
            'outstanding_balance' => $principal,
            'status' => Loan::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        $running = 0.0;

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $amount = $sequence === $count
                ? Money::round($principal - $running)
                : Money::round($principal / $count);

            $running = Money::round($running + $amount);

            $loan->installments()->create([
                'sequence' => $sequence,
                'due_date' => Carbon::parse($start)->addMonthsNoOverflow($sequence - 1)->toDateString(),
                'amount' => $amount,
                'status' => 'pending',
            ]);
        }

        return $loan;
    }
}
