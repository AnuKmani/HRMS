<?php

namespace App\Http\Requests;

use App\Models\Allowance;

/**
 * PUT /api/v1/allowances/{allowance}
 *
 * Same vocabulary as creation, a different ability, and every field becomes
 * optional - an update names what changed, not everything that is true.
 *
 * `employee_id` stays prohibited outright (see StoreAllowanceRequest): it is
 * not "you may not change it this time", it is "this endpoint does not
 * accept it, ever", and `prohibited` says so with a 422 rather than
 * silently ignoring a field the client believed it had set.
 */
class UpdateAllowanceRequest extends StoreAllowanceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('allowance')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['prohibited', 'integer', 'exists:employees,id'],

            'code' => ['sometimes', 'string', 'max:40', 'in:'.implode(',', Allowance::KNOWN_CODES)],
            'label' => ['sometimes', 'string', 'max:120'],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'lte:999999999.99'],

            'frequency' => ['sometimes', 'string', 'in:'.implode(',', Allowance::FREQUENCIES)],

            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],

            'payroll_year' => ['nullable', 'integer', 'min:2000', 'max:3000'],
            'payroll_month' => ['nullable', 'integer', 'min:1', 'max:12'],

            'status' => ['sometimes', 'string', 'in:'.implode(',', Allowance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
