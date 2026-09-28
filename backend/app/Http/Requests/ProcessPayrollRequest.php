<?php

namespace App\Http\Requests;

use App\Models\Payroll;
use App\Services\Payroll\PayrollPeriod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/payroll/process
 *
 * "Run March", and nothing else. The whole month or an explicit list of
 * employees within it.
 *
 * `year` and `month` are the only figures here: the calculation reads
 * `employees.salary`, allowances, overtime, adjustments, leave and
 * installments itself, so every field the spec lists as a payroll input is
 * deliberately absent from this body. Accepting them would make the endpoint
 * a way to *assert* a pay figure rather than compute one, and there would be
 * no way for a test to tell the two apart.
 *
 * `employee_ids` is a filter, not an override: it narrows who is touched and
 * never adds somebody who would not otherwise be in the run.
 */
class ProcessPayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('process', Payroll::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:3000'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'employee_ids' => ['sometimes', 'array', 'max:5000'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ];
    }

    /**
     * The five-year window, expressed as one message rather than as two
     * numbers duplicated here and in PayrollPeriod - the bounds are a
     * business rule about which periods this system will accept, and a
     * second copy would eventually disagree with the first.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $problems = [];

        if (($this->input('year') !== null) && ($this->input('month') !== null)) {
            $problems = PayrollPeriod::errors(
                (int) $this->input('year'),
                (int) $this->input('month'),
            );
        }

        return $problems + [
            'year.required' => 'Choose a year to process.',
            'month.required' => 'Choose a month to process.',
            'employee_ids.array' => 'employee_ids must be a list of employee ids.',
            'employee_ids.*.exists' => 'One of those employees no longer exists.',
        ];
    }
}
