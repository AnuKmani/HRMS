<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * One employee, in full — including the fields EmployeeResource leaves out.
 *
 * Used only by `GET /employees/{employee}`, whose controller has already run
 * `authorize('view', ...)`. Salary is the exception that needs its own check,
 * and the check is done here rather than trusting the caller to remember:
 *
 *   - the gate is `employees.salary.view`, a separate permission from
 *     `employees.view`, so holding the module does not disclose payroll;
 *   - `$request->user()` is null on an unauthenticated path, which resolves
 *     to false — the field fails closed rather than open;
 *   - the row access half of `EmployeePolicy::viewSalary` has already been
 *     established by the controller's `authorize('view')`, so re-running it
 *     here would cost a second visibility query to reach the same answer.
 *
 * If an endpoint ever builds this resource without that `authorize()` call,
 * the worst case is a salary reaching a caller who already holds
 * `employees.salary.view` — a small, explicitly seeded set (Super Admin, HR
 * Admin, Payroll Admin, Finance) — never a caller without it.
 */
class EmployeeDetailResource extends EmployeeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $resource */
        $resource = $this->resource;

        return array_merge(parent::toArray($request), [
            'user_id' => $resource->user_id,
            'date_of_birth' => $resource->date_of_birth?->toDateString(),
            'nationality' => $resource->nationality,
            'address' => $resource->address,

            'emergency_contact_name' => $resource->emergency_contact_name,
            'emergency_contact_phone' => $resource->emergency_contact_phone,
            'emergency_contact_relation' => $resource->emergency_contact_relation,

            // The whole point of the third permission. Absent (not null) when
            // the caller may not see it, so a client cannot tell "not paid
            // yet" from "not for you" by inspecting the key.
            'salary' => $request->user()?->can('employees.salary.view')
                ? $resource->salary
                : null,
            'salary_visible' => (bool) $request->user()?->can('employees.salary.view'),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ]);
    }
}
