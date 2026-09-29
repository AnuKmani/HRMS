<?php

namespace App\Services\Payroll;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The loan lifecycle, the installment schedule, and the balance arithmetic.
 *
 * Every write to `loans` and `loan_installments` goes through this class.
 * Two reasons rather than one:
 *
 *  - **the schedule is derived, not entered.** n installments from
 *    (principal, amount, count, start date) is arithmetic that must produce
 *    the same dates whether it was asked for by the API, a seeder or a
 *    test. Splitting it would be a second answer to "when is installment
 *    4 due?".
 *
 *  - **the balance has exactly one pair of mutators.** `claimInstallment()`
 *    subtracts what was actually taken and `releaseInstallments()` adds
 *    back what this particular run took. PayrollService calls them rather
 *    than touching `outstanding_balance`, so a recalculation that releases
 *    a claim and a second run that re-claims it cannot end up with a
 *    balance that was decremented twice and incremented once - the bug
 *    that makes a loan look half-repaid forever.
 *
 * Partial repayment is why both take an amount rather than assuming the
 * schedule's figure: a run may only take what leaves net salary at or
 * above `payroll.minimum_net_salary`, and the remainder stays on the
 * installment for the next run. See `LoanInstallment::deducted_amount`.
 *
 * Approval is a single decision recorded on the row rather than the Phase 6
 * materialised chain - see the migration's note on why a chain would add
 * configuration without adding a decision point. What *is* borrowed from
 * Phase 6 is its refusal style: "who" is the policy's question and gets a
 * 403, "what state is this row in" is this class's and gets a 409 that names
 * the state.
 */
final class LoanService
{
    /**
     * Create a loan or advance as a draft.
     *
     * When no installment amount is supplied the principal is divided
     * evenly rather than defaulted to the whole principal in one payment:
     * `number_of_installments: 12` with no amount would otherwise mean
     * twelve payments of the full sum. An even split also always leaves a
     * positive remainder for the final installment, which is what
     * {@see buildSchedule()} needs to close the balance.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Loan
    {
        return DB::transaction(function () use ($actor, $data) {
            $principal = (float) $data['principal_amount'];
            $count = max(1, (int) ($data['number_of_installments'] ?? 1));

            $loan = new Loan([
                'employee_id' => (int) $data['employee_id'],
                'loan_type' => $data['loan_type'] ?? Loan::TYPE_LOAN,
                'reference' => $data['reference'] ?? null,
                'principal_amount' => $principal,
                'installment_amount' => $this->installmentFor($data, $principal, $count),
                'number_of_installments' => $count,
                'start_date' => $data['start_date'],
                'outstanding_balance' => $principal,
                'status' => Loan::STATUS_DRAFT,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $actor->id,
            ]);

            $loan->save();

            return $loan;
        });
    }

    /**
     * Edit a draft. Nothing else may be edited - a submitted loan is waiting
     * on a decision, and changing the principal under an approver is the
     * exact thing approval exists to prevent.
     *
     * A draft has no schedule yet (one is minted at approval), so there is
     * nothing to roll back here beyond the row itself.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Loan $loan, array $data): Loan
    {
        // `draft` and only `draft`. `pending` used to pass through `isOpen()`
        // and reach `fill()`, which meant a borrower could keep editing a
        // request an approver was looking at - the exact window in which
        // "what I asked for" and "what was decided" must not be able to
        // differ. Once submitted, the answer to a change is withdraw and ask
        // again.
        if ($loan->status !== Loan::STATUS_DRAFT) {
            abort(409, 'Only a draft loan can be edited. Cancel it and start again.');
        }

        return DB::transaction(function () use ($loan, $data) {
            $loan->fill($data);

            $principal = (float) $loan->principal_amount;
            $count = max(1, (int) $loan->number_of_installments);

            $amountSupplied = array_key_exists('installment_amount', $data)
                && $data['installment_amount'] !== null
                && $data['installment_amount'] !== '';

            if ($amountSupplied) {
                $loan->installment_amount = Money::round($data['installment_amount']);
            } elseif (
                array_key_exists('principal_amount', $data)
                || array_key_exists('number_of_installments', $data)
            ) {
                // The schedule's inputs moved and no new amount was given, so
                // re-divide. Doing it only when something structural changed
                // is what stops a body that merely amends the remarks from
                // quietly rewriting a hand-chosen installment figure.
                $loan->installment_amount = Money::round($principal / $count);
            }

            $loan->number_of_installments = $count;
            $loan->outstanding_balance = $principal;
            $loan->save();

            return $loan;
        });
    }

    /**
     * The per-installment amount: the client's, or an even split.
     *
     * @param  array<string, mixed>  $data
     */
    private function installmentFor(array $data, float $principal, int $count): float
    {
        $supplied = $data['installment_amount'] ?? null;

        if ($supplied !== null && $supplied !== '') {
            return Money::round($supplied);
        }

        return Money::round($principal / $count);
    }

    public function submit(Loan $loan): Loan
    {
        if ($loan->status !== Loan::STATUS_DRAFT) {
            abort(409, 'This loan has already been submitted.');
        }

        $loan->update(['status' => Loan::STATUS_PENDING]);

        return $loan;
    }

    /**
     * Approve: record the decision, mint the schedule, and open repayment if
     * the start date has already arrived.
     *
     * The schedule is created here and only here, because three of its four
     * inputs (principal, installment amount, count) cannot change afterwards
     * and the fourth (start date) is frozen with the approval. Rebuilding it
     * on every read would let an edited row retroactively re-date payments
     * that were already taken.
     */
    public function approve(Loan $loan, User $actor, ?string $remarks = null): Loan
    {
        if ($loan->status !== Loan::STATUS_PENDING) {
            abort(409, 'Only a loan awaiting a decision can be approved.');
        }

        if ($loan->employee_id === $actor->employee?->id) {
            abort(403, 'You cannot approve your own loan.');
        }

        return DB::transaction(function () use ($loan, $actor, $remarks) {
            $loan->status = $loan->start_date->toDateString() <= today()->toDateString()
                ? Loan::STATUS_ACTIVE
                : Loan::STATUS_APPROVED;
            $loan->approved_by = $actor->id;
            $loan->approved_at = now();

            if ($remarks !== null && $remarks !== '') {
                $loan->remarks = $remarks;
            }

            $loan->save();

            $this->buildSchedule($loan);

            return $loan;
        });
    }

    public function reject(Loan $loan, User $actor, ?string $remarks = null): Loan
    {
        if ($loan->status !== Loan::STATUS_PENDING) {
            abort(409, 'Only a loan awaiting a decision can be rejected.');
        }

        $loan->status = Loan::STATUS_REJECTED;
        $loan->rejected_at = now();

        if ($remarks !== null && $remarks !== '') {
            $loan->remarks = $remarks;
        }

        $loan->save();

        return $loan;
    }

    public function cancel(Loan $loan): Loan
    {
        if (! $loan->isOpen()) {
            abort(409, 'Only a draft or a pending loan can be cancelled.');
        }

        $loan->status = Loan::STATUS_CANCELLED;
        $loan->cancelled_at = now();
        $loan->save();

        return $loan;
    }

    /**
     * Open every loan whose start date has arrived by the end of the period
     * being processed.
     *
     * Deliberately *not* a scheduled job. This application has exactly one
     * scheduled task and it exists because nothing else would ever run;
     * an `approved` loan becomes `active` at the first pay run that could
     * possibly repay it, which is also the first moment the distinction
     * matters. Running it there keeps the transition and the deduction in
     * one transaction instead of on two clocks.
     *
     * @return int rows opened
     */
    public function activateDue(string $periodEnd): int
    {
        return Loan::query()
            ->where('status', Loan::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $periodEnd)
            ->update(['status' => Loan::STATUS_ACTIVE]);
    }

    /**
     * Take a share of one installment out of a payroll run.
     *
     * The row is re-read under `lockForUpdate()` and what is still owed on
     * it is re-checked *inside* the lock, so two concurrent runs cannot
     * both observe room and both take it: the amount is clamped to what is
     * still outstanding, and the running total, the status and the balance
     * move in one write.
     *
     * `$amount` is not the scheduled figure - it is whatever the
     * calculation found fit above `payroll.minimum_net_salary`, which makes
     * a partial deduction a first-class outcome rather than an error. The
     * rest of the row stays outstanding and is offered to the next run.
     * Locking is what stops the same remainder being taken twice by two
     * runs racing each other.
     */
    public function claimInstallment(LoanInstallment $installment, Payroll $payroll, float $amount): void
    {
        $amount = Money::round($amount);

        if ($amount <= 0.0) {
            return;
        }

        $fresh = LoanInstallment::query()
            ->whereKey($installment->getKey())
            ->lockForUpdate()
            ->first();

        if ($fresh === null || ! in_array($fresh->status, LoanInstallment::CLAIMABLE, true)) {
            return;
        }

        $owed = Money::round((float) $fresh->amount - (float) $fresh->deducted_amount);
        $taken = Money::round(min($owed, $amount));

        if ($taken <= 0.0) {
            return;
        }

        $fresh->deducted_amount = Money::round((float) $fresh->deducted_amount + $taken);
        $fresh->status = $fresh->statusAfter((float) $fresh->deducted_amount);
        $fresh->payroll_id = $payroll->getKey();
        $fresh->deducted_at = now();
        $fresh->save();

        $loan = $fresh->loan()->lockForUpdate()->first();

        if ($loan !== null) {
            // The balance moves by what was *taken*, not by what the
            // schedule asked for - the difference is the carry-forward,
            // and a loan balance that assumed otherwise would show a debt
            // as repaid before the money had been collected.
            $balance = Money::round((float) $loan->outstanding_balance - $taken);
            $loan->outstanding_balance = max(0.0, $balance);
            $loan->status = $loan->outstanding_balance <= 0.0
                ? Loan::STATUS_COMPLETED
                : ($loan->status === Loan::STATUS_APPROVED ? Loan::STATUS_ACTIVE : $loan->status);
            $loan->save();
        }
    }

    /**
     * Give a payroll row's share of its installments back before it is
     * recalculated.
     *
     * The amount this run took is read from the run's own deduction lines
     * (`payroll_items`) rather than from a column on the installment,
     * because one installment can be shared: September may have taken
     * 600.00 of a 1,000.00 payment and October the other 400.00, and
     * releasing September must hand back exactly its 600.00 and leave
     * October's share standing. `payroll_id` is one column and cannot say
     * that; the payslip line always could.
     *
     * Without this a second run would find nothing outstanding for the
     * period and would silently pay the loan deduction as zero - a
     * recalculation that *removes* money is worse than one that fails.
     *
     * @return int deduction lines given back
     */
    public function releaseInstallments(Payroll $payroll): int
    {
        $claims = PayrollItem::query()
            ->where('payroll_id', $payroll->getKey())
            ->where('type', PayrollItem::TYPE_DEDUCTION)
            ->where('source_type', 'loan_installment')
            ->whereIn('code', [PayrollItem::CODE_LOAN, PayrollItem::CODE_ADVANCE])
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($claims as $claim) {
            $installment = LoanInstallment::query()
                ->whereKey($claim->source_id)
                ->lockForUpdate()
                ->first();

            if ($installment === null) {
                continue;
            }

            $released = Money::round(min((float) $claim->amount, (float) $installment->deducted_amount));

            if ($released <= 0.0) {
                continue;
            }

            $installment->deducted_amount = Money::round((float) $installment->deducted_amount - $released);
            $installment->status = $installment->statusAfter((float) $installment->deducted_amount);
            $installment->payroll_id = $this->holderOtherThan($installment, $payroll);
            $installment->deducted_at = (float) $installment->deducted_amount > 0.0
                ? $installment->deducted_at
                : null;
            $installment->save();

            $loan = $installment->loan()->lockForUpdate()->first();

            if ($loan === null) {
                continue;
            }

            $loan->outstanding_balance = Money::round(
                (float) $loan->outstanding_balance + $released,
            );

            // A loan that was completed by this run and is now being
            // recalculated is no longer complete: put it back to repaying so
            // the re-run can take the installment again.
            if ($loan->status === Loan::STATUS_COMPLETED) {
                $loan->status = Loan::STATUS_ACTIVE;
            }

            $loan->save();
        }

        return $claims->count();
    }

    /**
     * Which payroll still holds a claim on this installment, if any.
     *
     * `payroll_id` names the run that took money most recently - with
     * partial repayment it cannot name them all, and the full history is
     * on `payroll_items`. After a release it is recomputed from the
     * deduction lines that remain rather than cleared, so "taken by
     * October's run" never ends up pointing at a run that has just handed
     * its money back.
     */
    private function holderOtherThan(LoanInstallment $installment, Payroll $payroll): ?int
    {
        $holder = PayrollItem::query()
            ->where('source_type', 'loan_installment')
            ->where('source_id', $installment->getKey())
            ->where('type', PayrollItem::TYPE_DEDUCTION)
            ->whereNotNull('payroll_id')
            ->where('payroll_id', '!=', $payroll->getKey())
            ->max('payroll_id');

        return $holder === null ? null : (int) $holder;
    }

    /**
     * Mint all n installments for an approved loan.
     *
     * The last one carries the remainder so the schedule sums to the
     * principal exactly - a schedule of twelve equal payments that leaves
     * 0.04 outstanding is a loan that never completes, and `completed` is
     * decided by the balance reaching zero.
     */
    private function buildSchedule(Loan $loan): void
    {
        $loan->installments()->delete();

        $principal = (float) $loan->principal_amount;
        $count = max(1, (int) $loan->number_of_installments);
        $each = (float) $loan->installment_amount;
        $running = 0.0;

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $last = $sequence === $count;
            $amount = $last
                ? Money::round($principal - $running)
                : Money::round($each);

            if ($amount <= 0) {
                // The client asked for more installments than the principal
                // supports at this amount. Refuse the whole schedule rather
                // than writing a zero row that would never clear the debt.
                throw ValidationException::withMessages([
                    'installment_amount' => 'The installment amount must leave a positive amount for every installment.',
                ]);
            }

            $running = Money::round($running + $amount);

            $loan->installments()->create([
                'sequence' => $sequence,
                'due_date' => $loan->start_date->copy()->addMonthsNoOverflow($sequence - 1)->toDateString(),
                'amount' => $amount,
                'status' => LoanInstallment::STATUS_PENDING,
            ]);
        }
    }
}
