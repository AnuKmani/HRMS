<?php

namespace App\Services\Payroll;

use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * One employee's one month, fully calculated and not yet written anywhere.
 *
 * A value object rather than an array because the alternative is fourteen
 * positional elements, and because the invariants that matter - gross is the
 * sum of its parts, net is gross minus deductions - are asserted exactly
 * once here instead of being trusted at every call site.
 *
 * `items` is the itemisation the caller writes to `payroll_items`: each
 * element is a plain array in the shape `PayrollItem::create()` expects, so
 * this class never touches the database and can be unit-tested against a
 * fixture with no connection at all.
 *
 * `claims` is the repayment half of the same answer: for each installment
 * this run looked at, how much of it the run is taking
 * ({@see RepaymentAllocation}). The item lines and the claims are written
 * from the one calculation, so a deduction line of 600.00 and a loan
 * balance moved by 600.00 cannot come apart.
 *
 * `blockedReason` is non-null only when there was nothing to calculate - an
 * employee with no `salary`. Everything else is zero, and the caller stores
 * the row as `draft` so the gap is visible in the list instead of the
 * employee quietly not appearing in the run.
 */
final class PayrollCalculation
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  Collection<int, RepaymentAllocation>  $claims  what this run takes from each due installment
     */
    public function __construct(
        public readonly float $basicSalary,
        public readonly float $totalAllowances,
        public readonly float $overtimeAmount,
        public readonly int $overtimeMinutes,
        public readonly float $bonusAmount,
        public readonly float $grossSalary,
        public readonly float $lopDays,
        public readonly float $lopDivisor,
        public readonly float $lopAmount,
        public readonly float $loanDeduction,
        public readonly float $advanceDeduction,
        public readonly float $otherDeductions,
        public readonly float $totalDeductions,
        public readonly float $netSalary,
        public readonly array $items,
        public readonly Collection $claims,
        public readonly ?string $blockedReason = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  Collection<int, RepaymentAllocation>|null  $claims
     */
    public static function blocked(float $divisor, array $items = [], ?Collection $claims = null): self
    {
        return new self(
            basicSalary: 0.0,
            totalAllowances: 0.0,
            overtimeAmount: 0.0,
            overtimeMinutes: 0,
            bonusAmount: 0.0,
            grossSalary: 0.0,
            lopDays: 0.0,
            lopDivisor: $divisor,
            lopAmount: 0.0,
            loanDeduction: 0.0,
            advanceDeduction: 0.0,
            otherDeductions: 0.0,
            totalDeductions: 0.0,
            netSalary: 0.0,
            items: $items,
            claims: $claims ?? collect(),
            blockedReason: 'This employee has no salary on record, so there is nothing to calculate.',
        );
    }

    /**
     * The row as `payrolls` columns - the single place the two totals are
     * assembled, so `gross = parts` and `net = gross - deductions` hold by
     * construction rather than by discipline.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        return [
            'basic_salary' => $this->basicSalary,
            'total_allowances' => $this->totalAllowances,
            'overtime_amount' => $this->overtimeAmount,
            'overtime_minutes' => $this->overtimeMinutes,
            'bonus_amount' => $this->bonusAmount,
            'gross_salary' => $this->grossSalary,
            'lop_days' => $this->lopDays,
            'lop_divisor' => $this->lopDivisor,
            'lop_amount' => $this->lopAmount,
            'loan_deduction' => $this->loanDeduction,
            'advance_deduction' => $this->advanceDeduction,
            'other_deductions' => $this->otherDeductions,
            'total_deductions' => $this->totalDeductions,
            'net_salary' => $this->netSalary,
        ];
    }

    /**
     * Did the arithmetic add up? Asked before anything is written, because a
     * row whose totals disagree with its lines is worse than no row: every
     * later recalculation would start from figures that cannot be trusted.
     *
     * Tolerated at 0.01 - one cent - to allow for the rounding at each
     * materialised boundary. Anything larger is a bug, not a rounding
     * artefact.
     */
    public function balances(): bool
    {
        $tolerance = 0.01;

        $gross = Money::sum([
            $this->basicSalary,
            $this->totalAllowances,
            $this->overtimeAmount,
            $this->bonusAmount,
        ]);

        $deductions = Money::sum([
            $this->lopAmount,
            $this->loanDeduction,
            $this->advanceDeduction,
            $this->otherDeductions,
        ]);

        $earnings = 0.0;
        $subtracting = 0.0;

        foreach ($this->items as $item) {
            if ($item['type'] === 'earning') {
                $earnings = Money::round($earnings + (float) $item['amount']);
            } else {
                $subtracting = Money::round($subtracting + (float) $item['amount']);
            }
        }

        return abs($gross - $this->grossSalary) <= $tolerance
            && abs($deductions - $this->totalDeductions) <= $tolerance
            && abs($this->grossSalary - $this->totalDeductions - $this->netSalary) <= $tolerance
            && abs($earnings - $this->grossSalary) <= $tolerance
            && abs($subtracting - $this->totalDeductions) <= $tolerance;
    }
}
