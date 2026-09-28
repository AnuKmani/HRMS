<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;
use App\Support\Visibility;

/**
 * Loans and salary advances: self-service at the edges, one decision in the
 * middle, and a deliberate hole where `employees.view` would normally be.
 *
 * Four groups of question:
 *
 *  - **The employee** may ask for their own loan, edit it while it is a
 *    draft, submit it and withdraw it before a decision. They may never
 *    approve one - checked here and again in LoanService, because "nobody
 *    signs off on their own debt" is too important to rest on a single gate.
 *
 *  - **An approver** may act only while the row is pending and is not their
 *    own. `loans.approve` is the door; conditions 2 and 3 decide whether
 *    this particular person, on this particular row, right now.
 *
 *  - **`loans.manage`** may correct a row after the fact and read every
 *    loan in the building.
 *
 *  - **Reading** fails closed to *your own* with no `employees.view`
 *    fallback - see {@see Visibility::mayViewOthersLoans()} for why payroll
 *    and loans deliberately break the pattern attendance, leave, overtime
 *    and site reports all follow. A loan says how much somebody owes, and
 *    "you may see the staff directory" is not a decision about that.
 *
 * State (may a pending loan still be edited, may an active one be cancelled)
 * is LoanService's answer, as a 409 naming the state.
 */
class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('loans.view');
    }

    public function view(User $user, Loan $loan): bool
    {
        return Visibility::loanIsVisible($user, $loan);
    }

    /**
     * Ask for a loan. Requires an employee record because a loan is a claim
     * against a salary, and an account with no salary has nothing to repay
     * from - refusing at the door is kinder than letting the row exist and
     * then never producing an installment.
     */
    public function create(User $user): bool
    {
        return $user->can('loans.create') && $user->employee !== null;
    }

    /**
     * Edit a draft: your own, or anything with `loans.manage`.
     *
     * Not state-checked here - a submitted loan that cannot be edited is a
     * 409 from LoanService reading "Only a draft loan can be edited", which
     * is a better answer than this file's 403 would ever be.
     */
    public function update(User $user, Loan $loan): bool
    {
        return $this->owns($user, $loan) || $user->can('loans.manage');
    }

    /**
     * Submitting is the same privilege as editing: it is yours, or it is HR
     * pushing somebody else's through.
     */
    public function submit(User $user, Loan $loan): bool
    {
        return $this->owns($user, $loan) || $user->can('loans.manage');
    }

    /**
     * Say yes. Four conditions: the coarse permission, the row must be
     * pending, the caller must not be the borrower, and - because a loan is
     * a two-party request with no ordering - nothing else. See the migration
     * note on why this is not the Phase 6 materialised chain.
     *
     * Condition 2 is a *policy* condition rather than a service one, for the
     * same reason LeaveRequestPolicy states it: approving something already
     * decided should read as "you were never in a position to act on this",
     * which is a 403, while a *race* between two approvers is the service's
     * 409.
     */
    public function approve(User $user, Loan $loan): bool
    {
        if (! $user->can('loans.approve')) {
            return false;
        }

        if ($loan->status !== Loan::STATUS_PENDING) {
            return false;
        }

        return $user->employee?->id !== $loan->employee_id;
    }

    /**
     * Refusing requires everything approving requires: a row this caller
     * could not say yes to they may not say no to either, or "not the
     * approver" would mean one thing for one button and another for the
     * other.
     */
    public function reject(User $user, Loan $loan): bool
    {
        return $this->approve($user, $loan);
    }

    /**
     * Withdraw: your own while nothing has been decided, or `loans.manage`
     * for anything. Whether *this* row's state still permits it is
     * LoanService's 409.
     */
    public function cancel(User $user, Loan $loan): bool
    {
        return $this->owns($user, $loan) || $user->can('loans.manage');
    }

    private function owns(User $user, Loan $loan): bool
    {
        return $user->employee !== null
            && $user->employee->id === $loan->employee_id;
    }
}
