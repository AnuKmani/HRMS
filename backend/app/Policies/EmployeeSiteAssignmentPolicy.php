<?php

namespace App\Policies;

use App\Models\EmployeeSiteAssignment;
use App\Models\User;
use App\Support\Visibility;

/**
 * Assignments — history, not configuration.
 *
 * The schema is append-only: moving someone closes a row and inserts another,
 * and this policy deliberately has no `delete` at all. `DELETE
 * /employee-site-assignments/{id}` is not a route, because deleting a row
 * would erase where somebody stood on 3 March. Ending one is an update, and
 * that is the only mutation anyone is offered.
 *
 * Scope follows the site rather than the person: a supervisor who ran a site
 * sees every assignment that site ever carried, including rows for employees
 * who have since moved on. Restricting to "people I can see right now" would
 * punch holes in exactly the history that makes the table worth having.
 *
 * Your own rows are readable for the same reason your own employee record is
 * — it is your posting, not a disclosure.
 */
class EmployeeSiteAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assignments.view');
    }

    public function view(User $user, EmployeeSiteAssignment $assignment): bool
    {
        if ($this->isOwn($user, $assignment)) {
            return true;
        }

        if (! $user->can('assignments.view')) {
            return false;
        }

        return Visibility::assignmentIsVisible($user, $assignment);
    }

    public function create(User $user): bool
    {
        return $user->can('assignments.manage');
    }

    /**
     * Only ever used for a status/date transition — see
     * UpdateEmployeeSiteAssignmentRequest, which refuses an attempt to move
     * the row to a different person, site or project.
     */
    public function update(User $user, EmployeeSiteAssignment $assignment): bool
    {
        return $user->can('assignments.manage')
            && Visibility::assignmentIsVisible($user, $assignment);
    }

    private function isOwn(User $user, EmployeeSiteAssignment $assignment): bool
    {
        return $user->employee !== null && $user->employee->id === $assignment->employee_id;
    }
}
