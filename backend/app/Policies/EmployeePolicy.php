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

    /* ------------------------------------------------- Phase 10: banking */

    /*
    | Bank details live in their own table and their own resource — see
    | EmployeeBankAccount for why they are not columns on the row this
    | policy already governs. Only the *gate* sits here, because the route
    | is `/employees/{employee}/bank-account` and Laravel resolves a policy
    | from the class of its first argument: the question really is "may you
    | see this person's bank details?", and the person is what the caller
    | named.
    |
    | Two permissions rather than one, and neither is `employees.view`:
    |
    |   reading   your own — it is your account — or `employees.salary.view`
    |             (whoever is already trusted with pay figures) or
    |             `onboarding.manage` (whoever records them during
    |             onboarding);
    |   writing   those same two grants, never the employee themselves.
    |             Self-service entry of the account a salary is paid into is
    |             a decision for the operator to switch on, not a default:
    |             an unverified IBAN sitting in a payroll run is a failed
    |             payment nobody notices until payday.
    */

    /**
     * May this user read the employee's bank details?
     */
    public function viewBankAccount(User $user, Employee $employee): bool
    {
        if ($this->owns($user, $employee)) {
            return true;
        }

        return $this->mayRecordBankAccount($user);
    }

    /**
     * May this user set or change them?
     */
    public function updateBankAccount(User $user, Employee $employee): bool
    {
        return $this->mayRecordBankAccount($user);
    }

    private function mayRecordBankAccount(User $user): bool
    {
        return $user->can('employees.salary.view') || $user->can('onboarding.manage');
    }

    private function owns(User $user, Employee $employee): bool
    {
        return $user->employee !== null && $user->employee->id === $employee->id;
    }
}
