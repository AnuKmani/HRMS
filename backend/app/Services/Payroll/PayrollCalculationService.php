<?php

namespace App\Services\Payroll;

use App\Models\Allowance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\OvertimeRequest;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\SettingsService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything that decides what one person is owed for one month.
 *
 * **This class never writes.** It reads the operational records Phase 5 and
 * Phase 6 already validated, adds them up, and returns a
 * {@see PayrollCalculation}. Persisting is PayrollService's job, which is
 * what makes the arithmetic unit-testable against fixtures with no database
 * behind them and what keeps "who may lock a month" out of a class whose
 * only concern is "what does this month add up to".
 *
 * The formula, in one place, because it is the thing every test and every
 * document has to agree with:
 *
 * ```
 *   basic salary
 * + allowances active for the period
 * + approved, payroll-eligible overtime
 * + approved bonuses and adjustments
 * - unpaid days (LOP + approved leave on an unpaid type)
 * - the part of each due loan / salary-advance installment that fits
 *   above `payroll.minimum_net_salary` (the rest carries forward)
 * - approved other deductions and negative adjustments
 * = net salary
 * ```
 *
 * ## What it deliberately does NOT do
 *
 * **No attendance re-derivation.** Geofences, GPS accuracy, punch validity
 * and status rules were settled when the attendance row was written;
 * re-running them here would give the same day two answers. Payroll consumes
 * attendance's *consequences* - the shared working-day and holiday calendar
 * that sets the divisor, and the approved overtime that carries an
 * `attendance_id` - rather than its raw rows.
 *
 * **No absence deduction of its own.** An employee with no attendance row
 * and no leave in a month is not charged for it here. Unpaid days enter this
 * calculation through exactly one route: a leave request that Phase 6
 * converted to Loss of Pay, or an approved request on a type explicitly
 * flagged `is_paid = false`. Building a second absence-to-pay rule would let
 * two systems disagree about the same day, which is the failure mode the
 * whole "one place decides" principle exists to prevent.
 *
 * **No clamping, one floor.** If deductions exceed earnings the net is
 * negative and stays negative: clamping it to zero would break
 * `gross - deductions = net`, the one identity a payslip may never
 * violate, and would hide an over-deduction instead of putting it in front
 * of the person who can fix it.
 *
 * What this class *does* prevent is a **postponable** deduction forcing
 * net salary through `payroll.minimum_net_salary` (0 by default). Loans
 * and salary advances are owed to the company and can wait a month, so
 * their installments are sized against what is left after everything else
 * and only the part that fits is taken - the remainder stays outstanding
 * on the installment and is offered to the next run. Attendance, unpaid
 * leave and approved adjustments are not postponable: they are facts about
 * work done or not done, and rewriting them to protect a floor would
 * falsify the payslip. If those alone take the row below the floor, the
 * figure is reported honestly rather than quietly massaged.
 */
final class PayrollCalculationService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly LeaveDayCalculator $days,
    ) {}

    /**
     * Calculate one employee for one period.
     *
     * Must be called inside a transaction when the caller intends to write
     * the result: loan installments are read here and claimed afterwards,
     * and the two reads have to see the same state.
     */
    public function calculate(
        Employee $employee,
        int $year,
        int $month,
        string $periodStart,
        string $periodEnd,
    ): PayrollCalculation {
        $divisor = $this->divisor($periodStart, $periodEnd, $employee->primary_site_id);

        // The gap the spec asks to be visible rather than skipped: an
        // employee row with no salary produces a row in `draft`, not a
        // missing row. "Nobody ran payroll for this person" and "this person
        // was deliberately excluded" have to be different sights.
        if ($employee->salary === null || $employee->salary === '') {
            return PayrollCalculation::blocked($divisor);
        }

        $basic = Money::round($employee->salary);
        $items = [];

        /* ------------------------------------------------- basic salary */
        $items[] = $this->item(
            PayrollItem::TYPE_EARNING,
            PayrollItem::CODE_BASIC,
            'Basic salary',
            $basic,
            'employee',
            $employee->getKey(),
        );

        /* ---------------------------------------------------- allowances */
        $allowances = $this->allowances($employee, $year, $month, $periodStart);

        $allowanceTotal = 0.0;

        foreach ($allowances as $allowance) {
            $amount = Money::round($allowance->amount);
            $allowanceTotal = Money::round($allowanceTotal + $amount);

            $items[] = $this->item(
                PayrollItem::TYPE_EARNING,
                PayrollItem::CODE_ALLOWANCE,
                trim($allowance->label.' ('.$allowance->code.')'),
                $amount,
                'allowance',
                $allowance->getKey(),
            );
        }

        /* ---------------------------------------------------- overtime */
        [$overtimeMinutes, $overtimeAmount, $overtimeItem] = $this->overtime(
            $employee,
            $periodStart,
            $periodEnd,
            $basic,
            $divisor,
        );

        if ($overtimeItem !== null) {
            $items[] = $overtimeItem;
        }

        /* ------------------------------------ bonuses and other one-offs */
        [$bonusAmount, $otherDeduction, $adjustmentItems] = $this->adjustments(
            $employee,
            $year,
            $month,
        );

        foreach ($adjustmentItems as $adjustmentItem) {
            $items[] = $adjustmentItem;
        }

        $gross = Money::sum([$basic, $allowanceTotal, $overtimeAmount, $bonusAmount]);

        /* --------------------------------------- unpaid days (LOP, etc.) */
        [$lopDays, $unpaidLeaveDays, $dayItems, $unpaidItems] = $this->unpaidDays(
            $employee,
            $periodStart,
            $periodEnd,
            $divisor,
            $basic,
        );

        foreach ($dayItems as $dayItem) {
            $items[] = $dayItem;
        }

        foreach ($unpaidItems as $unpaidItem) {
            $items[] = $unpaidItem;
        }

        $unpaidDayTotal = Money::round($lopDays + $unpaidLeaveDays);
        $lopAmount = Money::sum(array_merge(
            array_map(fn (array $row) => (float) $row['amount'], $dayItems),
            array_map(fn (array $row) => (float) $row['amount'], $unpaidItems),
        ));

        /* ------------------------------------------ loans and advances */
        $installments = $this->dueInstallments($employee, $periodEnd);

        // Everything else in this month is already spent or already owed,
        // so what is left over is the most a repayment may take:
        //
        //     room = gross - (LOP + approved adjustments) - floor
        //
        // The floor is a setting rather than a 0 in this file - see
        // `minimumNetSalary()`. It is applied here, once, before any
        // installment is sized, so no caller and no later step can decide
        // for itself how far into a salary a repayment is allowed to reach.
        $floor = $this->minimumNetSalary();
        $room = max(
            0.0,
            Money::round($gross - Money::sum([$lopAmount, $otherDeduction]) - $floor),
        );

        $loanDeduction = 0.0;
        $advanceDeduction = 0.0;
        $claims = collect();

        // Oldest first, always: when there is not enough room for every
        // installment due, the debt that has been waiting longest is the
        // one that gets paid.
        foreach ($installments as $installment) {
            $scheduled = Money::round($installment->amount);
            $before = max(0.0, Money::round((float) $installment->deducted_amount));
            $owed = max(0.0, Money::round($scheduled - $before));
            $actual = Money::round(min($owed, $room));
            $room = Money::round($room - $actual);

            $claim = new RepaymentAllocation($installment, $scheduled, $before, $actual);
            $claims->push($claim);

            if ($claim->isDeferred()) {
                // Nothing fits. No deduction line is written, because a
                // line on a payslip is proof that money moved - and no
                // claim is made, so the row is still outstanding and the
                // next run is offered it again.
                continue;
            }

            $loan = $installment->loan;

            if ($loan !== null && $loan->isSalaryAdvance()) {
                $advanceDeduction = Money::round($advanceDeduction + $actual);
            } else {
                $loanDeduction = Money::round($loanDeduction + $actual);
            }

            $items[] = $this->item(
                PayrollItem::TYPE_DEDUCTION,
                $loan !== null && $loan->isSalaryAdvance()
                    ? PayrollItem::CODE_ADVANCE
                    : PayrollItem::CODE_LOAN,
                sprintf(
                    '%s repayment %d of %d%s',
                    $loan?->typeLabel() ?? 'Loan',
                    (int) $installment->sequence,
                    (int) ($loan?->number_of_installments ?? 0),
                    $loan?->reference ? ' ('.$loan->reference.')' : '',
                ),
                $actual,
                'loan_installment',
                $installment->getKey(),
                [
                    'loan_id' => $installment->loan_id,
                    'sequence' => $installment->sequence,

                    // The four numbers the API and the loan screen keep
                    // apart, recorded on the line that proves this run's
                    // share of them: what the schedule says, what is being
                    // taken now, and what is left afterwards.
                    'scheduled_amount' => Money::decimal($scheduled),
                    'deducted_amount' => Money::decimal($actual),
                    'remaining_amount' => Money::decimal($claim->remaining()),
                ],
            );
        }

        $otherDeductions = Money::sum([$otherDeduction]);

        $totalDeductions = Money::sum([
            $lopAmount,
            $loanDeduction,
            $advanceDeduction,
            $otherDeductions,
        ]);

        $net = Money::round($gross - $totalDeductions);

        return new PayrollCalculation(
            basicSalary: $basic,
            totalAllowances: $allowanceTotal,
            overtimeAmount: $overtimeAmount,
            overtimeMinutes: $overtimeMinutes,
            bonusAmount: $bonusAmount,
            grossSalary: $gross,
            lopDays: $unpaidDayTotal,
            lopDivisor: $divisor,
            lopAmount: $lopAmount,
            loanDeduction: $loanDeduction,
            advanceDeduction: $advanceDeduction,
            otherDeductions: $otherDeductions,
            totalDeductions: $totalDeductions,
            netSalary: $net,
            items: $items,
            claims: $claims,
        );
    }

    /**
     * The net salary a run may never fall below.
     *
     * A setting rather than a constant for the same reason the LOP divisor
     * is: "0, never pay a negative salary" and "nobody takes home less than
     * 1,000.00" are two legitimate answers to a question about a company's
     * payroll policy, and which one is correct is not an engineering
     * decision. Read through SettingsService, so changing it is an UPDATE
     * rather than a deploy.
     *
     * Never negative: a configured floor below zero would be a way of
     * re-introducing the exact bug this rule exists to stop, so anything
     * under 0 is read as 0.
     */
    public function minimumNetSalary(): float
    {
        return max(0.0, Money::round($this->settings->float('payroll.minimum_net_salary', 0.0)));
    }

    /**
     * The divisor a lost day is priced against.
     *
     * Two modes, both configured in `settings` rather than in code:
     *
     *  - `fixed` (default): `payroll.lop_divisor`, 30 by default. The
     *    calendar-month convention - a day is a thirtieth of the month
     *    regardless of how long February is.
     *  - `working_days`: the working days this particular period contains,
     *    counted by LeaveDayCalculator from `working_hours.default` plus the
     *    holiday calendar. A day is then worth *more* in a short month with
     *    two public holidays, which is what a working-day contract means.
     *
     * The same number also prices an hour of overtime (see `hourlyRate()`),
     * so there is exactly one divisor in the system rather than two that can
     * drift apart.
     *
     * Falls back to `fixed` when the working-day count comes back empty -
     * a period that is entirely holidays or entirely weekends must not
     * produce a division by zero, and returning the configured constant is
     * the answer that keeps the row payable.
     */
    public function divisor(string $periodStart, string $periodEnd, ?int $siteId = null): float
    {
        $mode = $this->settings->string('payroll.lop_divisor_mode', 'fixed');

        if ($mode === 'working_days') {
            $count = $this->days->count($periodStart, $periodEnd, $siteId);

            if ($count > 0) {
                return (float) round($count, 4);
            }
        }

        $configured = $this->settings->float('payroll.lop_divisor', 30.0);

        return $configured > 0 ? (float) round($configured, 4) : 30.0;
    }

    /**
     * The hourly rate overtime is priced at.
     *
     * `basic / divisor / daily hours` - a daily rate divided by the length
     * of a working day, so the same divisor governs a lost day and an extra
     * hour and the two can never disagree about what a day of this person's
     * time is worth.
     *
     * `daily_hours` is read from `working_hours.default` rather than
     * introduced as a payroll setting: it is already the organisation's
     * answer to "how long is a working day", it is already editable, and a
     * second copy of it in another group would eventually disagree.
     */
    public function hourlyRate(float $basic, float $divisor): float
    {
        $dailyHours = (float) ($this->settings->json('working_hours.default')['daily_hours'] ?? 8);

        if ($divisor <= 0 || $dailyHours <= 0) {
            return 0.0;
        }

        return Money::round($basic / $divisor / $dailyHours);
    }

    /* ----------------------------------------------------------- sources */

    /**
     * @return Collection<int, Allowance>
     */
    private function allowances(
        Employee $employee,
        int $year,
        int $month,
        string $periodStart,
    ): Collection {
        return Allowance::query()
            ->active()
            ->where('employee_id', $employee->getKey())
            ->get()
            ->filter(fn (Allowance $allowance) => $allowance->appliesTo($year, $month, $periodStart))
            ->values();
    }

    /**
     * Approved, payroll-eligible overtime only.
     *
     * Two conditions and both are required. `status = approved` alone is not
     * enough because a chain can be reversed; `payroll_eligible` alone is
     * not enough because the flag is only ever written alongside an
     * approval. Requiring both means a draft, a pending claim, a rejected
     * claim and a cancelled claim are all excluded by construction rather
     * than by a list somebody has to remember to extend.
     *
     * @return array{0: int, 1: float, 2: array<string, mixed>|null}
     */
    private function overtime(
        Employee $employee,
        string $periodStart,
        string $periodEnd,
        float $basic,
        float $divisor,
    ): array {
        $claims = OvertimeRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->where('payroll_eligible', true)
            ->whereBetween('overtime_date', [$periodStart, $periodEnd])
            ->get();

        $minutes = 0;

        foreach ($claims as $claim) {
            $minutes += (int) ($claim->approved_minutes ?? $claim->requested_minutes);
        }

        if ($minutes <= 0) {
            return [0, 0.0, null];
        }

        $multiplier = $this->settings->float('payroll.overtime_rate_multiplier', 1.5);
        $rate = Money::round($this->hourlyRate($basic, $divisor) * $multiplier);
        $amount = Money::round($minutes / 60 * $rate);

        return [$minutes, $amount, $this->item(
            PayrollItem::TYPE_EARNING,
            PayrollItem::CODE_OVERTIME,
            sprintf(
                'Overtime - %d min at %.2fx (%s)',
                $minutes,
                $multiplier,
                $claims->count().' claim'.($claims->count() === 1 ? '' : 's'),
            ),
            $amount,
            'overtime_request',
            null,
            ['claims' => $claims->pluck('id')->all(), 'rate' => $rate, 'multiplier' => $multiplier],
            $minutes / 60,
            $rate,
        )];
    }

    /**
     * Approved bonuses, other deductions and adjustments for the period.
     *
     * Only `approved` rows are read. A bonus still sitting in `pending` is a
     * request, not a payment - including it would pay money nobody signed
     * off on, and excluding a *rejected* row is the same rule seen from the
     * other side.
     *
     * @return array{0: float, 1: float, 2: array<int, array<string, mixed>>}
     */
    private function adjustments(Employee $employee, int $year, int $month): array
    {
        $rows = PayrollAdjustment::query()
            ->payable()
            ->where('employee_id', $employee->getKey())
            ->period($year, $month)
            ->get();

        $bonus = 0.0;
        $deduction = 0.0;
        $items = [];

        foreach ($rows as $row) {
            // Belt and braces. The FormRequest refuses a malformed amount at
            // the door; this refuses one that arrived some other way - a
            // seeder, tinker, a direct UPDATE - rather than letting
            // signedAmount() quietly return 0 and drop the row from the
            // payslip as though it had never existed. Failing the whole run
            // is correct: a pay period that silently omits a line is worse
            // than one that stops and says which line is wrong.
            PayrollAdjustment::assertAmountIsUsable($row->type, (float) $row->amount);

            $signed = Money::round($row->signedAmount());
            $amount = abs($signed);

            if ($amount == 0.0) {
                continue;
            }

            if ($signed >= 0) {
                $bonus = Money::round($bonus + $amount);

                $items[] = $this->item(
                    PayrollItem::TYPE_EARNING,
                    $row->type === PayrollAdjustment::TYPE_BONUS
                        ? PayrollItem::CODE_BONUS
                        : PayrollItem::CODE_ADJUSTMENT,
                    $row->description,
                    $amount,
                    'payroll_adjustment',
                    $row->getKey(),
                );
            } else {
                $deduction = Money::round($deduction + $amount);

                $items[] = $this->item(
                    PayrollItem::TYPE_DEDUCTION,
                    $row->type === PayrollAdjustment::TYPE_OTHER_DEDUCTION
                        ? PayrollItem::CODE_OTHER
                        : PayrollItem::CODE_ADJUSTMENT,
                    $row->description,
                    $amount,
                    'payroll_adjustment',
                    $row->getKey(),
                );
            }
        }

        return [$bonus, $deduction, $items];
    }

    /**
     * Days in this period that are not paid for.
     *
     * Two sources, deliberately kept apart on the slip even though they
     * share one day count on the row:
     *
     *  - `lop` - a request Phase 6 converted to Loss of Pay. `lop_days` was
     *    recorded at conversion time rather than derived later, precisely so
     *    payroll would not have to re-decide it.
     *  - `approved` on a type with `is_paid = false` - the seeded `Unpaid
     *    Leave` and `Other` types, which hold no balance pot and therefore
     *    consume no entitlement. These are unpaid by policy rather than by
     *    conversion, and they would otherwise be paid as though they were
     *    annual leave.
     *
     * A request that spans a month boundary is pro-rated by calendar days so
     * that its portions across the months it covers add back up to the whole.
     * That is an approximation - `lop_days` counts working days while the
     * split counts calendar days - and it is a deliberate one: the alternative
     * is either double-counting the crossing request or dropping it from both
     * months, and a single-cent discrepancy nobody can see beats a whole day
     * that is either charged twice or not at all.
     *
     * @return array{0: float, 1: float, 2: array<int, array<string, mixed>>, 3: array<int, array<string, mixed>>}
     */
    private function unpaidDays(
        Employee $employee,
        string $periodStart,
        string $periodEnd,
        float $divisor,
        float $basic,
    ): array {
        $daily = Money::proRate($basic, 1, $divisor);

        $base = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('start_date', '<=', $periodEnd)
            ->where('end_date', '>=', $periodStart)
            ->with('leaveType');

        $lopRows = (clone $base)
            ->where('status', LeaveRequest::STATUS_LOP)
            ->get();

        $unpaidRows = (clone $base)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->get()
            ->filter(fn (LeaveRequest $leave) => ! $leave->isPaid());

        return [
            $this->sumProrated($lopRows, $periodStart, $periodEnd),
            $this->sumProrated($unpaidRows, $periodStart, $periodEnd),
            $this->dayItems($lopRows, $periodStart, $periodEnd, $daily, PayrollItem::CODE_LOP, 'Loss of pay'),
            $this->dayItems($unpaidRows, $periodStart, $periodEnd, $daily, PayrollItem::CODE_LEAVE_UNPAID, 'Unpaid leave'),
        ];
    }

    /**
     * One deduction line per request, priced at the daily rate.
     *
     * Per request rather than one aggregated line: a slip that says "loss of
     * pay, 6 days" when two separate requests produced 3 and 3 cannot be
     * reconciled against the leave history by anybody except the person who
     * already knows the answer.
     *
     * @param  Collection<int, LeaveRequest>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dayItems(
        Collection $rows,
        string $periodStart,
        string $periodEnd,
        float $daily,
        string $code,
        string $label,
    ): array {
        $items = [];

        foreach ($rows as $row) {
            $days = $this->proratedDays($row, $periodStart, $periodEnd);

            if ($days <= 0) {
                continue;
            }

            $items[] = $this->item(
                PayrollItem::TYPE_DEDUCTION,
                $code,
                sprintf('%s - %s (%s to %s)', $label, $this->daysLabel($days), $row->start_date?->toDateString(), $row->end_date?->toDateString()),
                Money::round($daily * $days),
                'leave_request',
                $row->getKey(),
                ['days' => $days, 'daily_rate' => $daily],
                $days,
                $daily,
            );
        }

        return $items;
    }

    private function daysLabel(float $days): string
    {
        return $days == (float) (int) $days
            ? (int) $days.' day'.((int) $days === 1 ? '' : 's')
            : rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.').' days';
    }

    /**
     * Sum prorated days across a set of requests.
     */
    private function sumProrated(Collection $rows, string $periodStart, string $periodEnd): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $total = Money::round($total + $this->proratedDays($row, $periodStart, $periodEnd));
        }

        return $total;
    }

    /**
     * How much of one request's unpaid days fall inside this period.
     */
    private function proratedDays(LeaveRequest $leave, string $periodStart, string $periodEnd): float
    {
        $from = $leave->start_date;
        $to = $leave->end_date;

        if ($from === null || $to === null) {
            return 0.0;
        }

        $insideFrom = max($from->toDateString(), $periodStart);
        $insideTo = min($to->toDateString(), $periodEnd);

        if ($insideFrom > $insideTo) {
            return 0.0;
        }

        $all = (float) abs($from->startOfDay()->diffInDays($to->startOfDay())) + 1;
        $inside = (float) abs(
            CarbonImmutable::parse($insideFrom)->startOfDay()
                ->diffInDays(CarbonImmutable::parse($insideTo)->startOfDay()),
        ) + 1;

        if ($all <= 0 || $inside <= 0) {
            return 0.0;
        }

        return Money::round((float) $leave->lop_days * $inside / $all);
    }

    /**
     * Installments this run may still take from: still owing, and due on or
     * before the end of the period.
     *
     * Two changes from a plain "due this month" query, both load-bearing:
     *
     *  - **`due_date <= period_end`, with no lower bound.** The schedule's
     *    due date says when a repayment was *supposed* to be taken, not
     *    when it actually was. An installment the previous run had to
     *    leave behind - because it would have pushed net salary through
     *    the floor - is still owed, and a query that only looked inside the
     *    current month would never find it again: the money would quietly
     *    stop being collected. Overdue rows are picked up by the first run
     *    with room for them, in due-date order.
     *
     *  - **`status in CLAIMABLE`**, i.e. `pending` or `partially_deducted`.**
     *    The partial remainder of an installment is outstanding by
     *    definition, and a row a human marked `skipped` or `adjusted` is
     *    never taken at all. An installment already claimed by an earlier
     *    run of this same payroll has been released back to `pending` by
     *    the caller first (or was never claimed), and the transaction
     *    around the caller is what makes this read and the claim atomic.
     *
     * @return Collection<int, LoanInstallment>
     */
    private function dueInstallments(
        Employee $employee,
        string $periodEnd,
    ): Collection {
        return LoanInstallment::query()
            ->whereIn('status', LoanInstallment::CLAIMABLE)
            ->where('due_date', '<=', $periodEnd)
            ->whereHas('loan', function ($query) use ($employee) {
                $query->where('employee_id', $employee->getKey())
                    ->whereIn('status', Loan::REPAYING);
            })
            ->with('loan')
            ->orderBy('due_date')
            ->orderBy('loan_id')
            ->orderBy('sequence')
            ->get()
            ->values();
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $type,
        string $code,
        string $description,
        float $amount,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?array $metadata = null,
        ?float $quantity = null,
        ?float $rate = null,
    ): array {
        return [
            'type' => $type,
            'code' => $code,
            'description' => $description,
            'quantity' => $quantity,
            'rate' => $rate,
            'amount' => Money::decimal($amount),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'metadata' => $metadata,
        ];
    }
}
