<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * The one place that writes an employee row.
 *
 * A row this central has three classes of rule attached to it, and they live
 * in three different places on purpose:
 *
 *  - **shape** (lengths, formats, uniqueness) — StoreEmployeeRequest /
 *    UpdateEmployeeRequest, so a bad payload produces one 422 envelope;
 *  - **authority** (may this person change a salary, may they see this
 *    record) — EmployeePolicy, so it cannot be skipped by a route that
 *    forgets its middleware;
 *  - **consistency** (two related writes must land together, or not at all)
 *    — here, inside a transaction, because that is the only place it can be
 *    guaranteed.
 *
 * Nothing else in the app calls `Employee::create()` directly. That is what
 * makes an audit hook safe to add later: it goes in this class, one method,
 * and immediately covers every caller including imports and seeders that go
 * through the API. No fake audit logging exists here yet — deliberately.
 */
class EmployeeService
{
    /**
     * @param  array<string, mixed>  $data  already validated and authorised
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $employee = new Employee;
            $employee->fill($this->normalise($data));
            $employee->save();

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $data  already validated and authorised
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $employee->fill($this->normalise($data));
            $employee->save();

            return $employee->refresh();
        });
    }

    /**
     * Tidy the handful of things a validation rule cannot express without
     * being unreasonable about it.
     *
     * Deliberately does NOT touch `photo_path` or `user_id`: neither is ever
     * in `$data`, and a normaliser that re-assigned them would be exactly the
     * kind of silent repair that hides a bug.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        foreach (['first_name', 'middle_name', 'last_name', 'nationality'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = trim($data['email']);
        }

        if (isset($data['employee_code']) && is_string($data['employee_code'])) {
            $data['employee_code'] = trim($data['employee_code']);
        }

        return $data;
    }
}
