<?php

namespace App\Policies;

use App\Models\LeaveBalance;
use App\Models\User;
use App\Support\Visibility;

/**
 * Leave balances: read with leave, write with HR.
 *
 * The split exists because the two questions are genuinely different. "How
 * many days do I have left?" is something every employee asks about their own
 * pot and needs answered honestly; "move this person's entitlement" changes
 * what the next request will be allowed, which is an HR act with
 * `leave.balance.manage` behind it.
 *
 * Row scope reuses the leave rule rather than inventing a second one: a
 * balance describes a person's absence entitlement, so it is visible exactly
 * where their leave requests are visible.
 */
class LeaveBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('leave.balance.view');
    }

    /**
     * Your own pot, or one belonging to somebody whose leave you may read.
     *
     * The colleague case goes through Visibility::mayViewOthersBalances()
     * rather than through `employeeIsVisible()` alone, and the difference
     * matters: `employeesAreScopedFor()` answers *false* for an unlisted role
     * such as Employee, which `employeeIsVisible()` reads as "no narrowing
     * applies" and therefore as *yes*. Without the coarse question in front of
     * it, anybody holding `leave.balance.view` — which the ordinary Employee
     * role needs for their own summary — could open a colleague's pot.
     */
    public function view(User $user, LeaveBalance $leaveBalance): bool
    {
        if ($user->employee?->id === $leaveBalance->employee_id) {
            return true;
        }

        if (! Visibility::mayViewOthersBalances($user)) {
            return false;
        }

        $employee = $leaveBalance->employee;

        return $employee !== null && Visibility::employeeIsVisible($user, $employee);
    }

    /**
     * Entitlement, carry-forward and adjustment are HR's to change.
     *
     * Coarse only: `leave.balance.manage` is held by HR Admin and Super
     * Admin, and neither of them needs a row check on a number they are
     * explicitly trusted to set.
     */
    public function update(User $user, LeaveBalance $leaveBalance): bool
    {
        return $user->can('leave.balance.manage');
    }
}
