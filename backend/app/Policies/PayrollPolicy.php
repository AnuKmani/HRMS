<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;
use App\Support\Visibility;

/**
 * Payroll: the coarse gate opens the module, Visibility decides the rows.
 *
 * Three groups of question, and the split between them is the whole design:
 *
 *  - **Reading** (`viewAny` / `view`) asks only "may you see payroll at
 *    all?" and then hands the row-level answer to
 *    {@see Visibility::payrollIsVisible()}. That is what lets an Employee
 *    hold `payroll.view` - without it the endpoint is a 403 before any
 *    narrowing could run - while still seeing exactly one row: their own.
 *
 *  - **Changing the numbers** (`process`, `recalculate`, `review`,
 *    `finalize`) is separated into `payroll.process` and `payroll.manage`
 *    rather than collapsing onto one "payroll admin" grant, because the two
 *    are genuinely different acts: one restates a month, the other corrects
 *    what goes into it. A role may do either without the other.
 *
 *  - **Making it permanent** (`lock`) is the narrowest grant in the system
 *    and there is no unlock anywhere - a permission-gated escape hatch would
 *    turn the lock into a suggestion.
 *
 * **State is not this file's business.** Whether a row may be recalculated
 * from its current status is PayrollService's question, answered as a 409
 * that names the state. Answering it here would hand a caller "This action
 * is unauthorized" for what is really "already locked" - a message that
 * tells them what they did rather than what they did wrong. What *is*
 * enforced here is who may ask at all.
 */
class PayrollPolicy
{
    /**
     * Coarse gate for the collection. Held by Employee too - see the class
     * note for why that does not mean they read the company's.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.view');
    }

    public function view(User $user, Payroll $payroll): bool
    {
        return Visibility::payrollIsVisible($user, $payroll);
    }

    /**
     * Run or re-run a month's calculation.
     *
     * `payroll.process` only - deliberately not `payroll.manage`. Being
     * allowed to add an allowance does not imply being allowed to restate
     * everyone's pay for March, and separating them is what makes the pair
     * useful for a role that does one and not the other.
     */
    public function process(User $user): bool
    {
        return $user->can('payroll.process');
    }

    /**
     * Recalculate one row. Same grant as running the month, because it is
     * the same act with a narrower scope.
     */
    public function recalculate(User $user, Payroll $payroll): bool
    {
        return $user->can('payroll.process');
    }

    /**
     * Mark a row reviewed. This is the *preparation* side - so
     * `payroll.manage`, not `payroll.process` and not `payroll.lock`.
     */
    public function review(User $user, Payroll $payroll): bool
    {
        return $user->can('payroll.manage');
    }

    public function finalize(User $user, Payroll $payroll): bool
    {
        return $user->can('payroll.manage');
    }

    /**
     * Make it permanent. Held by Payroll Admin and Super Admin and by nobody
     * else, so HR Admin - which may prepare and process - stops at the last
     * irreversible step. That asymmetry is the point of having the grant at
     * all rather than reusing `payroll.manage`.
     */
    public function lock(User $user, Payroll $payroll): bool
    {
        return $user->can('payroll.lock');
    }

    /**
     * Company totals with no employee-level rows behind them.
     *
     * A separate ability rather than a branch of `view()`: the summary
     * endpoint's contract is *shape*, not just access. Management holds this
     * and not the ability to read individual salaries, so "summary-only" is
     * a real, reachable position in the permission model rather than a
     * comment about what should be filtered.
     */
    public function summary(User $user): bool
    {
        return $user->can('payroll.summary.view');
    }

    /**
     * List salary slips. Separate from `viewAny` because the two grants are
     * separate: a role can hold `salary_slips.view` without `payroll.view`
     * and should reach `/salary-slips` but be refused `/payroll`.
     */
    public function slipIndex(User $user): bool
    {
        return $user->can('salary_slips.view');
    }

    /**
     * Read one slip - and, by extension, produce its PDF.
     *
     * Deliberately no separate `salary_slips.pdf` grant (Phase 7's site
     * reports have one because a report and a report's photographs are
     * different disclosures). A slip *is* the payroll row in document form,
     * so splitting them would create a permission whose only effect is to
     * hand over the same figures in a different container.
     *
     * The document contains salary, so there is no "salary figures" fallback
     * here: `salary_slips.view` is the answer, and it is held only by roles
     * that should see payslips at all.
     */
    public function slip(User $user, Payroll $payroll): bool
    {
        if (! $user->can('salary_slips.view')) {
            return false;
        }

        if ($user->employee?->id === $payroll->employee_id) {
            return true;
        }

        return Visibility::mayViewOthersSalarySlips($user);
    }
}
