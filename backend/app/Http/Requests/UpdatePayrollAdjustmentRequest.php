<?php

namespace App\Http\Requests;

use App\Models\PayrollAdjustment;

/**
 * PUT /api/v1/payroll-adjustments/{adjustment}
 *
 * Same rules as creation, different ability - see UpdateAllowanceRequest.
 *
 * `employee_id` and the period are prohibited on update for a reason that
 * matters more here than on an allowance: moving an adjustment to another
 * employee *or another month* would drop a figure into a period it was never
 * approved for, which is precisely what requiring the period in the first
 * place was meant to prevent.
 */
class UpdatePayrollAdjustmentRequest extends StorePayrollAdjustmentRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('adjustment')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['prohibited', 'integer', 'exists:employees,id'],
            'payroll_year' => ['prohibited', 'integer', 'min:2000', 'max:3000'],
            'payroll_month' => ['prohibited', 'integer', 'min:1', 'max:12'],

            'type' => ['sometimes', 'string', 'in:'.implode(',', PayrollAdjustment::TYPES)],
            'description' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'lte:999999999.99'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
