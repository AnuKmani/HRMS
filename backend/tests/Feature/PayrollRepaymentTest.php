<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\PayrollItem;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * Payroll may not take a salary below the floor, and a repayment it cannot
 * fit above the floor is carried forward rather than dropped.
 *
 * Four figures are asserted separately in every test below, because
 * conflating any two of them is the bug this pass exists to prevent:
 *
 *   scheduled   `loan_installments.amount`         what the schedule asks for
 *   deducted    `loan_installments.deducted_amount` what runs have taken
 *   remaining   scheduled - deducted                what is still owed here
 *   outstanding `loans.outstanding_balance`         what is left on the loan
 *
 * The floor itself is `payroll.minimum_net_salary`, a setting whose
 * factory default is 0 - "never pay a negative salary" - so every test
 * starts from the same rule an untouched install would run with.
 *
 * Figures below are derived from `salary = 5000` and the seeded divisor of
 * 30 unless a test says otherwise, so nothing here depends on rounding
 * happening anywhere but in `Money`.
 */
class PayrollRepaymentTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    private const YEAR = 2026;

    private const MONTH = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    /* -------------------------------------------------- the ordinary case */

    public function test_a_scheduled_installment_is_taken_in_full_when_the_floor_allows_it(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 12000, 12, '2026-09-05');

        $this->process();

        $row = $this->row($employee->id);

        $this->assertSame(1000.0, (float) $row['loan_deduction']);
        $this->assertSame(1000.0, (float) $row['total_deductions']);
        $this->assertSame(29000.0, (float) $row['net_salary']);

        $first = $this->installment($loan, 1);
        $this->assertSame(LoanInstallment::STATUS_DEDUCTED, $first->status);
        $this->assertSame(1000.0, (float) $first->deducted_amount);
        $this->assertSame(0.0, $first->remainingAmount());
        $this->assertNotNull($first->payroll_id, 'A fully taken installment still names the run that took it.');

        $this->assertSame(11000.0, (float) $loan->fresh()->outstanding_balance);
    }

    /* --------------------------------------------------------- the floor */

    public function test_a_repayment_may_never_push_net_salary_below_zero(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 500]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 3000, 3, '2026-09-05');

        $this->process();

        $row = $this->row($employee->id);

        // The scheduled installment is 1,000.00 against a salary of 500.00.
        // The seeded floor of 0 is what stops it: exactly what fits is
        // taken, and the net lands on zero rather than below it.
        $this->assertSame(500.0, (float) $row['gross_salary']);
        $this->assertSame(500.0, (float) $row['loan_deduction']);
        $this->assertSame(0.0, (float) $row['net_salary']);
        $this->assertGreaterThanOrEqual(0.0, (float) $row['net_salary']);

        $first = $this->installment($loan, 1);
        $this->assertSame(LoanInstallment::STATUS_PARTIALLY_DEDUCTED, $first->status);
        $this->assertSame(500.0, (float) $first->deducted_amount);
        $this->assertSame(500.0, $first->remainingAmount());
        $this->assertSame(2500.0, (float) $loan->fresh()->outstanding_balance);

        // One line, for what actually moved. A payslip that listed the
        // full 1,000.00 would be proof of money nobody was paid.
        $lines = PayrollItem::query()
            ->where('payroll_id', $row['id'])
            ->where('code', PayrollItem::CODE_LOAN)
            ->get();

        $this->assertCount(1, $lines);
        $this->assertSame(500.0, (float) $lines->first()->amount);
    }

    public function test_the_floor_is_a_setting_that_caps_a_partial_deduction(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 5000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 30000, 6, '2026-09-05');

        $this->assertSame(
            '0',
            Setting::query()->where('key', 'payroll.minimum_net_salary')->value('value'),
            'The development default is "never negative", seeded rather than hard-coded.',
        );

        $this->process();

        $row = $this->row($employee->id);
        $this->assertSame(5000.0, (float) $row['loan_deduction']);
        $this->assertSame(0.0, (float) $row['net_salary']);

        // Retune the rule and re-run the same month: the deduction moves
        // with it, which is the whole point of the number living in
        // `settings` rather than in the calculation.
        $this->setFloor(1000.0);

        $this->recalculate($row['id']);

        $again = $this->row($employee->id);
        $this->assertSame(4000.0, (float) $again['loan_deduction']);
        $this->assertSame(1000.0, (float) $again['net_salary']);

        $first = $this->installment($loan, 1);
        $this->assertSame(LoanInstallment::STATUS_PARTIALLY_DEDUCTED, $first->status);
        $this->assertSame(4000.0, (float) $first->deducted_amount);
        $this->assertSame(1000.0, $first->remainingAmount());
        $this->assertSame(26000.0, (float) $loan->fresh()->outstanding_balance);
    }

    public function test_unpaid_days_are_taken_before_a_repayment_and_are_never_rewritten(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $this->lopLeave($employee, '2026-09-07', '2026-09-07', 1);
        $loan = $this->repayable($employee, 15000, 1, '2026-09-05');

        $this->setFloor(20000.0);
        $this->process();

        $row = $this->row($employee->id);

        // Attendance is a fact about work not done, so it is charged in
        // full; the repayment is what flexes. Net lands exactly on the
        // floor, and the shortfall is taken out of a debt that can wait.
        $this->assertSame(1000.0, (float) $row['lop_amount']);
        $this->assertSame(9000.0, (float) $row['loan_deduction']);
        $this->assertSame(10000.0, (float) $row['total_deductions']);
        $this->assertSame(20000.0, (float) $row['net_salary']);

        $first = $this->installment($loan, 1);
        $this->assertSame(LoanInstallment::STATUS_PARTIALLY_DEDUCTED, $first->status);
        $this->assertSame(9000.0, (float) $first->deducted_amount);
        $this->assertSame(6000.0, $first->remainingAmount());
        $this->assertSame(6000.0, (float) $loan->fresh()->outstanding_balance);
    }

    /* -------------------------------------------------------- the carry */

    public function test_the_remainder_carries_forward_and_the_next_run_takes_it(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 5000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 30000, 6, '2026-09-05');
        $this->setFloor(1000.0);

        $this->process();

        $september = $this->row($employee->id);
        $this->assertSame(4000.0, (float) $september['loan_deduction']);

        $this->process(self::YEAR, 10);

        $october = $this->row($employee->id, self::YEAR, 10);
        $this->assertSame(4000.0, (float) $october['loan_deduction']);
        $this->assertSame(1000.0, (float) $october['net_salary']);

        // September's 1,000.00 remainder was owed *before* October's own
        // installment, and due-date order is what makes the oldest debt
        // the first one collected.
        $first = $this->installment($loan, 1);
        $this->assertSame(LoanInstallment::STATUS_DEDUCTED, $first->status);
        $this->assertSame(5000.0, (float) $first->deducted_amount);
        $this->assertSame(0.0, $first->remainingAmount());

        $second = $this->installment($loan, 2);
        $this->assertSame('2026-10-05', $second->due_date->toDateString());
        $this->assertSame(LoanInstallment::STATUS_PARTIALLY_DEDUCTED, $second->status);
        $this->assertSame(3000.0, (float) $second->deducted_amount);
        $this->assertSame(2000.0, $second->remainingAmount());

        $this->assertSame(22000.0, (float) $loan->fresh()->outstanding_balance);
    }

    public function test_an_installment_the_floor_blocked_is_retried_rather_than_skipped(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 1000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 6000, 6, '2026-09-05');
        $this->setFloor(1000.0);

        $this->process();

        $row = $this->row($employee->id);

        // Nothing fits, so nothing is taken - and nothing is written off
        // either: the row is still `pending`, still carries its due date,
        // and still has every rupee outstanding.
        $this->assertSame(0.0, (float) $row['loan_deduction']);
        $this->assertSame(1000.0, (float) $row['net_salary']);

        $first = $this->installment($loan, 1);
        $this->assertSame(LoanInstallment::STATUS_PENDING, $first->status);
        $this->assertSame(0.0, (float) $first->deducted_amount);
        $this->assertNull($first->payroll_id);
        $this->assertSame(6000.0, (float) $loan->fresh()->outstanding_balance);

        $this->assertSame(
            0,
            PayrollItem::query()
                ->where('payroll_id', $row['id'])
                ->where('code', PayrollItem::CODE_LOAN)
                ->count(),
            'No deduction line for money that never moved.',
        );

        // October arrives with room in the month. The installment is a
        // month overdue, and a query that only looked at October's own due
        // dates would have lost this debt for good.
        $this->setFloor(0.0);
        $this->process(self::YEAR, 10);

        $october = $this->row($employee->id, self::YEAR, 10);
        $this->assertSame(1000.0, (float) $october['loan_deduction']);

        $first->refresh();
        $this->assertSame(LoanInstallment::STATUS_DEDUCTED, $first->status);
        $this->assertSame(1000.0, (float) $first->deducted_amount);
        $this->assertSame(5000.0, (float) $loan->fresh()->outstanding_balance);
    }

    /* ----------------------------------------------- taking it only once */

    public function test_running_the_same_month_twice_takes_the_installment_once(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 5000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 30000, 6, '2026-09-05');
        $this->setFloor(1000.0);

        $this->process();
        $this->process();

        $row = $this->row($employee->id);

        $this->assertSame(4000.0, (float) $row['loan_deduction']);
        $this->assertSame(4000.0, (float) $this->installment($loan, 1)->deducted_amount, 'Not 8,000.00.');
        $this->assertSame(26000.0, (float) $loan->fresh()->outstanding_balance, 'The balance moved once.');
        $this->assertSame(1, PayrollItem::query()->where('payroll_id', $row['id'])
            ->where('code', PayrollItem::CODE_LOAN)->count());
    }

    public function test_a_recalculation_gives_the_take_back_before_it_takes_it_again(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 5000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 30000, 6, '2026-09-05');
        $this->setFloor(1000.0);

        $this->process();
        $id = $this->row($employee->id)['id'];

        $this->recalculate($id);
        $this->recalculate($id);

        $again = $this->row($employee->id);

        $this->assertSame(4000.0, (float) $again['loan_deduction'], 'Released, then taken again - once.');
        $this->assertSame(4000.0, (float) $this->installment($loan, 1)->deducted_amount);
        $this->assertSame(26000.0, (float) $loan->fresh()->outstanding_balance);
        $this->assertSame(LoanInstallment::STATUS_PARTIALLY_DEDUCTED, $this->installment($loan, 1)->status);
    }

    public function test_a_locked_month_cannot_change_its_loan_deduction(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 5000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 30000, 6, '2026-09-05');
        $this->setFloor(1000.0);

        $this->process();
        $id = $this->row($employee->id)['id'];

        $this->postJson("/api/v1/payroll/{$id}/review")->assertOk();
        $this->postJson("/api/v1/payroll/{$id}/finalize")->assertOk();
        $this->postJson("/api/v1/payroll/{$id}/lock")->assertOk();

        // Retune the rule to something that would change the figure, then
        // ask twice: the locked row refuses the recalculation outright...
        $this->setFloor(4000.0);
        $this->postJson("/api/v1/payroll/{$id}/recalculate")->assertStatus(409);

        // ... and a whole new run over the month skips it rather than
        // quietly restating a figure somebody has already been paid from.
        $report = $this->process();
        $this->assertSame(1, $report['skipped']);

        $locked = $this->row($employee->id);
        $this->assertSame(4000.0, (float) $locked['loan_deduction']);
        $this->assertSame(1000.0, (float) $locked['net_salary']);
        $this->assertSame(4000.0, (float) $this->installment($loan, 1)->deducted_amount);
        $this->assertSame(26000.0, (float) $loan->fresh()->outstanding_balance);
    }

    /* ----------------------------------------------------- advances, API */

    public function test_a_salary_advance_is_capped_exactly_like_a_loan(): void
    {
        [, $employee] = $this->makeSeat('Employee', ['salary' => 5000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $advance = $this->repayable($employee, 30000, 6, '2026-09-05', Loan::TYPE_SALARY_ADVANCE);
        $this->setFloor(1000.0);

        $this->process();

        $row = $this->row($employee->id);

        $this->assertSame(0.0, (float) $row['loan_deduction']);
        $this->assertSame(4000.0, (float) $row['advance_deduction']);
        $this->assertSame(1000.0, (float) $row['net_salary']);

        $first = $this->installment($advance, 1);
        $this->assertSame(LoanInstallment::STATUS_PARTIALLY_DEDUCTED, $first->status);
        $this->assertSame(4000.0, (float) $first->deducted_amount);
        $this->assertSame(1000.0, $first->remainingAmount());
        $this->assertSame(26000.0, (float) $advance->fresh()->outstanding_balance);

        $line = PayrollItem::query()
            ->where('payroll_id', $row['id'])
            ->where('code', PayrollItem::CODE_ADVANCE)
            ->firstOrFail();

        $this->assertSame(4000.0, (float) $line->amount);
        $this->assertSame('5000.00', $line->metadata['scheduled_amount']);
        $this->assertSame('4000.00', $line->metadata['deducted_amount']);
        $this->assertSame('1000.00', $line->metadata['remaining_amount']);
    }

    public function test_the_schedule_reports_scheduled_deducted_and_remaining_apart(): void
    {
        [$owner, $employee] = $this->makeSeat('Employee', ['salary' => 5000]);
        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);

        $loan = $this->repayable($employee, 30000, 6, '2026-09-05');
        $this->setFloor(1000.0);

        $this->process();

        // Read back by the borrower, who is the person these four figures
        // are actually for.
        $this->become($owner);

        $data = $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()->json('data');
        $first = $data['installments'][0];

        // Four questions, four answers, none of them derived on the client.
        $this->assertSame('5000.00', $first['amount']);
        $this->assertSame('4000.00', $first['deducted_amount']);
        $this->assertSame('1000.00', $first['remaining_amount']);
        $this->assertSame(LoanInstallment::STATUS_PARTIALLY_DEDUCTED, $first['status']);

        $this->assertSame('30000.00', $data['principal_amount']);
        $this->assertSame('4000.00', $data['repaid_amount']);
        $this->assertSame('26000.00', $data['outstanding_balance']);

        // The next thing a run will try is the remainder that is already
        // overdue, not the untouched installment beside it.
        $this->assertSame(1, $data['next_installment']['sequence']);
        $this->assertSame('1000.00', $data['next_installment']['remaining_amount']);
        $this->assertSame(
            LoanInstallment::STATUS_PARTIALLY_DEDUCTED,
            $data['next_installment']['status'],
        );
    }

    /* ----------------------------------------------------------- helpers */

    private function process(int $year = self::YEAR, int $month = self::MONTH): array
    {
        return $this->postJson('/api/v1/payroll/process', ['year' => $year, 'month' => $month])
            ->assertOk()
            ->json('data');
    }

    private function recalculate(int $payrollId): void
    {
        $this->postJson("/api/v1/payroll/{$payrollId}/recalculate")
            ->assertOk()
            ->assertJsonPath('data.status', 'calculated');
    }

    /**
     * The row as the API renders it, so assertions are on what a screen
     * would actually be handed.
     *
     * @return array<string, mixed>
     */
    private function row(int $employeeId, int $year = self::YEAR, int $month = self::MONTH): array
    {
        $items = $this->getJson(
            sprintf('/api/v1/payroll?year=%d&month=%d&employee_id=%d', $year, $month, $employeeId),
        )->assertOk()->json('data.items');

        $this->assertNotEmpty($items, "No payroll row for employee {$employeeId}.");

        return $items[0];
    }

    /**
     * Set `payroll.minimum_net_salary` the way an operator would — a row in
     * `settings`, not a constant — through the `Setting` model, so the
     * `saved` event flushes the cache exactly as §5.3 of
     * docs/ARCHITECTURE.md requires, with an explicit refresh behind it.
     */
    private function setFloor(float $value): void
    {
        Setting::query()
            ->where('key', 'payroll.minimum_net_salary')
            ->firstOrFail()
            ->update(['value' => Money::decimal($value)]);

        app(SettingsService::class)->refresh();
    }

    private function installment(Loan $loan, int $sequence): LoanInstallment
    {
        return $loan->installments()->where('sequence', $sequence)->firstOrFail();
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
            'reason' => 'Unpaid.',
            'requested_days' => $days,
            'status' => LeaveRequest::STATUS_LOP,
            'lop_days' => $days,
            'approved_at' => now(),
        ]);
    }

    /**
     * An approved loan whose repayment is open - the state `LoanService`
     * leaves it in once a decision has been made - with its schedule minted
     * the way `buildSchedule()` would mint it.
     */
    private function repayable(
        Employee $employee,
        float $principal,
        int $count,
        string $start,
        string $type = Loan::TYPE_LOAN,
    ): Loan {
        $loan = Loan::create([
            'employee_id' => $employee->id,
            'loan_type' => $type,
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
                'status' => LoanInstallment::STATUS_PENDING,
            ]);
        }

        return $loan;
    }
}
