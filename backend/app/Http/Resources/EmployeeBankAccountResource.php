<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where one employee gets paid — and the one resource that may say those
 * words out loud.
 *
 * This class exists *because* the values must not appear in
 * EmployeeResource. That one is returned by the directory, the timesheet
 * pickers and the approval screens, all of which are reachable by a great
 * many roles; a field that has to be remembered-not-to-echoed in every one
 * of them is a field that will one day be echoed. So the account lives in
 * its own table, is reached by its own two routes, is gated by
 * EmployeePolicy::viewBankAccount / updateBankAccount — your own, or
 * `employees.salary.view`, or `onboarding.manage` — and is serialised only
 * here.
 *
 * Everything named in the payload is named deliberately. There is no
 * `getAttributes()` spread, no `$this->resource->toArray()` and no
 * `appends`: the safest shape for sensitive data is one that no blanket
 * serialization ever accidentally includes, so each field is written out
 * and adding a column to the table does not add it to the API.
 *
 * `iban` and `account_number` are decrypted by their casts for the reader
 * who is allowed to see them — the alternative, a masked form, would mean
 * HR cannot pay anybody from what the API told them, which is the entire
 * reason the table exists.
 */
class EmployeeBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'account_holder_name' => $this->account_holder_name,
            'bank_name' => $this->bank_name,
            'iban' => $this->iban,
            'account_number' => $this->account_number,
            'swift_bic' => $this->swift_bic,
            'currency' => $this->currency,
            'status' => $this->status,
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
