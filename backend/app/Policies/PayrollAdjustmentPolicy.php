<?php

namespace App\Policies;

use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Support\Visibility;

/**
 * Bonuses, other deductions and manual adjustments.
 *
 * Read is the ordinary pattern - own rows always, everybody's with
 * `payroll.manage`. Write is where this policy is stricter than it looks:
 * **every** mutation needs `payroll.manage`, including for the person the
 * adjustment is about.
 *
 * That is deliberate. The alternative - "an employee may see and withdraw
 * their own bonus" - would mean the recipient could cancel a figure a
 * manager approved, or decline a bonus that a budget was already committed
 * to, through the same door HR uses to correct a typo. It is a payroll
 * input either way, so it has exactly one class of owner.
 *
 * Whether the row may still change from *its current state* is not asked
 * here. A rejected adjustment that cannot be re-approved is Payroll's
 * service call to refuse, with a 409 naming the state - see
 * PayrollPolicy's class note on why the two questions are kept apart.
 */
class PayrollAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.view');
    }

    public function view(User $user, PayrollAdjustment $adjustment): bool
    {
        return Visibility::payrollAdjustmentIsVisible($user, $adjustment);
    }

    public function create(User $user): bool
    {
        return $user->can('payroll.manage');
    }

    public function update(User $user, PayrollAdjustment $adjustment): bool
    {
        return $user->can('payroll.manage');
    }

    /**
     * Sign it off. `payroll.manage` rather than a dedicated `approve`
     * grant: the catalog already has `payroll.manage` as "this role decides
     * what goes into pay", and a second grant with identical grants would
     * be a rule written twice rather than a rule with more precision.
     */
    public function approve(User $user, PayrollAdjustment $adjustment): bool
    {
        return $user->can('payroll.manage');
    }

    public function reject(User $user, PayrollAdjustment $adjustment): bool
    {
        return $user->can('payroll.manage');
    }

    public function cancel(User $user, PayrollAdjustment $adjustment): bool
    {
        return $user->can('payroll.manage');
    }
}
