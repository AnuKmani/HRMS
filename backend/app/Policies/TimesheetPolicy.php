<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;
use App\Support\Visibility;

/**
 * Timesheets: the same scope as the attendance they are derived from.
 *
 * There is deliberately no `update()` and no approval ability. A timesheet is
 * a snapshot of a row nobody is allowed to edit (Phase 5 has no attendance
 * override either), so letting a manager "approve a timesheet" would assert
 * nothing that approving the underlying attendance would not — and would put
 * a second, weaker door next to a door that is currently the only one.
 *
 * Sign-off, when it comes, arrives with its own ability and its own service
 * method. Until then this policy answers exactly two questions: may you list
 * them, and may you open this one.
 */
class TimesheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('timesheets.view');
    }

    public function view(User $user, Timesheet $timesheet): bool
    {
        return Visibility::timesheetIsVisible($user, $timesheet);
    }

    /**
     * Regenerate a period from attendance — an administrative act over other
     * people's records, so `timesheets.manage` rather than a row check.
     *
     * The *scope* of what it may touch is applied afterwards, in
     * TimesheetService, through the same Visibility rule this policy reads.
     */
    public function generate(User $user): bool
    {
        return $user->can('timesheets.manage');
    }
}
