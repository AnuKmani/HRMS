<?php

namespace App\Services\Payroll;

use App\Models\LoanInstallment;
use App\Support\Money;

/**
 * How much of one scheduled repayment this run is taking, and what is left.
 *
 * Four figures that payroll and the loan screen have always needed and
 * until now had no single home for:
 *
 *   scheduled  what the schedule says installment n is worth
 *   before     what earlier runs have already taken from it
 *   actual     what *this* run is taking
 *   remaining  scheduled - before - actual, carried into the next run
 *
 * It exists as a value object rather than four parallel variables because
 * the item written to `payroll_items`, the claim written to
 * `loan_installments` and the balance written to `loans` must all describe
 * the same deduction. Passing one object that carries every part of the
 * answer to each of those three writers is what stops them drifting - a
 * payslip line of 600.00 against a loan balance moved by 1000.00 is the
 * exact disagreement this class makes impossible to express.
 *
 * Written only by {@see PayrollCalculationService}, claimed only by
 * {@see LoanService::claimInstallment()}.
 */
final readonly class RepaymentAllocation
{
    public function __construct(
        public LoanInstallment $installment,
        public float $scheduled,
        public float $before,
        public float $actual,
    ) {}

    /**
     * Taken in full by this run - the installment is finished with.
     */
    public function isComplete(): bool
    {
        return $this->remaining() <= 0.0;
    }

    /**
     * Taken, but not all of it: the rest of the row is outstanding.
     */
    public function isPartial(): bool
    {
        return $this->actual > 0.0 && $this->remaining() > 0.0;
    }

    /**
     * Nothing could be taken from this installment in this run - the floor
     * was reached first. The row stays outstanding and is offered to the
     * next run; it is not skipped and not written off.
     */
    public function isDeferred(): bool
    {
        return $this->actual <= 0.0;
    }

    /**
     * Still owed on this installment after this run - the figure the loan
     * screen shows beside "partly deducted".
     */
    public function remaining(): float
    {
        return Money::round($this->scheduled - $this->before - $this->actual);
    }
}
