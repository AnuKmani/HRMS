<?php

namespace App\Policies;

use App\Models\Allowance;
use App\Models\User;
use App\Support\Visibility;

/**
 * Allowances: read your own, write everybody's.
 *
 * The narrowest policy in the system, because allowances are a pure payroll
 * input. An employee seeing their own entitlement is normal - it is on their
 * payslip anyway - while a role that cannot touch payroll has no reason to
 * enumerate what the company pays everybody in rent top-ups.
 *
 * State questions (is this allowance inside its effective window, is a
 * one-time row missing its period) belong to the FormRequest and to
 * PayrollCalculationService, not here.
 */
class AllowancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.view');
    }

    public function view(User $user, Allowance $allowance): bool
    {
        return Visibility::allowanceIsVisible($user, $allowance);
    }

    /**
     * Create: `payroll.manage` and nothing looser. There is no
     * `allowances.create` in the catalog - an employee cannot nominate their
     * own top-up, which would make the approval that bonuses have pointless
     * by comparison.
     */
    public function create(User $user): bool
    {
        return $user->can('payroll.manage');
    }

    /**
     * Edit or withdraw. Your own rows are *readable* but not editable: an
     * allowance is a contractual figure, and letting the recipient restate it
     * would defeat having it as a row at all.
     */
    public function update(User $user, Allowance $allowance): bool
    {
        return $user->can('payroll.manage');
    }

    /**
     * Soft delete, same grant as editing - see the migration on why the row
     * disappears from the picker while already-calculated payrolls keep
     * their own frozen line items.
     */
    public function delete(User $user, Allowance $allowance): bool
    {
        return $user->can('payroll.manage');
    }
}
