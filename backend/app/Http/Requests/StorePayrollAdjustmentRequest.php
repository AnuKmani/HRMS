<?php

namespace App\Http\Requests;

use App\Models\PayrollAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST|PUT /api/v1/payroll-adjustments
 *
 * A bonus, an other-deduction or a signed adjustment for one named period.
 *
 * The sign rules are enforced here and again in PayrollService. Both,
 * because the request rules guard the API and the service guard protects
 * the calculation from a seeder, a tinker session or a direct model write -
 * "no negative bonuses" has to hold for all three, and only one of them
 * goes through this class.
 *
 * The rules themselves:
 *
 *   bonus            amount > 0   - the type supplies the plus
 *   other_deduction  amount > 0   - the type supplies the minus
 *   adjustment       amount != 0  - it carries its own sign
 *
 * That is the "no arbitrary negative amounts" requirement: a `bonus` can
 * never be negative because the value is refused outright, and a deduction
 * can never be *stored* as a negative number because its sign comes from the
 * column that says what kind of thing it is. Nothing downstream ever has to
 * decide which way to add a figure.
 *
 * The period is required rather than optional, for the reason the allowance
 * request gives: an unanchored adjustment lands in whichever month is
 * processed next.
 */
class StorePayrollAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PayrollAdjustment::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'exists:employees,id'],

            'payroll_year' => ['required', 'integer', 'min:2000', 'max:3000'],
            'payroll_month' => ['required', 'integer', 'min:1', 'max:12'],

            'type' => ['required', 'string', 'in:'.implode(',', PayrollAdjustment::TYPES)],
            'description' => ['required', 'string', 'max:255'],

            'amount' => ['required', 'numeric', 'lte:999999999.99'],

            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The sign rules. Written against the request rather than as a plain
     * `gt:0` because which rule applies depends on `type`, and a validator
     * rule that could not see another field would have to allow every value
     * and then be checked somewhere else anyway.
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('amount') || ! $this->filled('type')) {
                return;
            }

            // On a partial update the type may be absent while the amount is
            // not - the row's own type is then the one to test against, read
            // from the route model. Without that, "lower this bonus to 0"
            // would be refused for an unknown type rather than for being
            // zero, which is not what the caller did wrong.
            $type = (string) ($this->input('type')
                ?? $this->route('adjustment')?->type
                ?? '');
            $amount = (float) $this->input('amount');

            foreach (PayrollAdjustment::amountProblems($type, $amount) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Choose the employee this adjustment belongs to.',
            'employee_id.exists' => 'That employee does not exist.',
            'payroll_year.required' => 'Choose the payroll year this adjustment belongs to.',
            'payroll_month.required' => 'Choose the payroll month this adjustment belongs to.',
            'type.in' => 'Type must be bonus, other_deduction or adjustment.',
            'description.required' => 'Describe what this adjustment is for.',
            'amount.required' => 'Enter an amount.',
            'amount.lte' => 'An adjustment amount cannot exceed 999999999.99.',
        ];
    }
}
