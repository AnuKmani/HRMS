<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertEmployeeBankAccountRequest;
use App\Http\Resources\EmployeeBankAccountResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use Illuminate\Http\JsonResponse;

/**
 * GET|PUT /api/v1/employees/{employee}/bank-account
 *
 * Where salary is paid into, on the two routes in this application that
 * carry **no `permission:` middleware at all** — decided entirely by
 * EmployeePolicy::viewBankAccount / updateBankAccount, in the same way
 * `GET /employees/{id}` is.
 *
 * The reason a coarse gate has to be absent is the reason the policy has to
 * be present: the door has to admit an ordinary employee reading *their
 * own* account, and `employees.salary.view` — the permission the rest of
 * the payroll surface sits behind — is precisely the permission an Employee
 * does not hold. A shared middleware could not have said "your own, or one
 * of two grants" without ceasing to be shared.
 *
 * The data itself is treated as sensitive in three places that are all
 * worth naming, because each one alone would be incomplete:
 *
 *  - **its own table** — EmployeeResource never has to remember not to
 *    echo it, because the value is not on the row it serialises;
 *  - **its own resource** — every field is written out by name; there is no
 *    `getAttributes()` spread and no `appends`, so adding a column to the
 *    table does not add it to the API;
 *  - **encrypted columns** — see EmployeeBankAccount for the operational
 *    rule that implies about APP_KEY.
 *
 * Nothing is logged either, which matters most here: the rules in the form
 * request report on the *field* that failed, never on the value, so an
 * IBAN cannot end up in an exception message or a nightly log line.
 */
class EmployeeBankAccountController extends Controller
{
    /**
     * GET /api/v1/employees/{employee}/bank-account
     */
    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('viewBankAccount', $employee);

        $account = EmployeeBankAccount::query()
            ->where('employee_id', $employee->id)
            ->first();

        abort_if($account === null, 404, 'No bank account on file.');

        return ApiResponse::success('Employee bank account.', new EmployeeBankAccountResource($account));
    }

    /**
     * PUT /api/v1/employees/{employee}/bank-account
     *
     * An upsert rather than a create/update pair: there is one account per
     * employee (see the unique index), so offering two routes would only
     * mean two ways to express the same write and a client that had to pick
     * between them.
     */
    public function update(UpsertEmployeeBankAccountRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->validated();
        $data['recorded_by'] = $request->user()?->id;

        $account = EmployeeBankAccount::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            $data,
        );

        return ApiResponse::success('Bank account saved.', new EmployeeBankAccountResource($account));
    }
}
