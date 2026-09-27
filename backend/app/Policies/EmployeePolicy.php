<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Visibility;

/**
 * Row-level authorization for the employee master.
 *
 * Three separate questions, deliberately kept separate:
 *
 *  1. *May you open the module?* — `viewAny`, the coarse permission.
 *  2. *May you see this person?* — `view`, the permission narrowed by
 *     config/hrms.php plus an absolute exception for your own record.
 *  3. *May you see what they are paid?* — `viewSalary`, a third permission
 *     on top of the first two.
 *
 * Salary is the reason this policy exists in this shape. Pay sits on the
 * employee row, so the obvious implementation — "you can read employees, so
 * you can read the salary" — would hand every role holding `employees.view`
 * (Management, Project Manager, Site Supervisor, Finance ...) a payroll
 * disclosure. Instead the field has its own gate, and the resource that
 * renders it asks this policy rather than checking the permission alone, so
 * a user who somehow held `employees.salary.view` without `employees.view`
 * still reads nothing.
 *
 * Your own record is never a privilege anyone grants you: an Employee can
 * open their own profile without holding `employees.view` at all. What they
 * cannot do is *list* — `viewAny` denies, and scoping cannot rescue a
 * collection the coarse gate already refused.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($this->owns($user, $employee)) {
            return true;
        }

        if (! $user->can('employees.view')) {
            return false;
        }

        return Visibility::employeeIsVisible($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.create');
    }

    /**
     * Editing is both a coarse permission and, for a scoped role, a matter
     * of proximity — a Project Manager holding `employees.update` (none do
     * today, but the grant is a config change away) still only reaches the
     * workforce they run.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.update')
            && Visibility::employeeIsVisible($user, $employee);
    }

    /**
     * Soft delete only — the schema keeps the row, and with it every
     * assignment and history that points at it.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->can('employees.delete')
            && Visibility::employeeIsVisible($user, $employee);
    }

    /**
     * Not `employees.view`, and not `payroll.view` either.
     *
     * A caller must be allowed to read the person *and* hold the explicit
     * salary permission — two independent conditions, both required.
     */
    public function viewSalary(User $user, Employee $employee): bool
    {
        return $user->can('employees.salary.view') && $this->view($user, $employee);
    }

    /**
     * Symmetric with reading: you may not write a figure you are not allowed
     * to see. Enforced again in the form request, which is where the value
     * arrives, so the rule travels with the payload rather than only with
     * the route.
     */
    public function manageSalary(User $user, Employee $employee): bool
    {
        return $this->viewSalary($user, $employee);
    }

    private function owns(User $user, Employee $employee): bool
    {
        return $user->employee !== null && $user->employee->id === $employee->id;
    }
}
