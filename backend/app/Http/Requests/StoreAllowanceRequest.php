<?php

namespace App\Http\Requests;

use App\Models\Allowance;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST|PUT /api/v1/allowances
 *
 * What a client may claim about a recurring or one-time allowance.
 *
 * Two cross-field rules live here rather than in the service because they
 * are about *the shape of the request*, and a malformed shape is a 422 before
 * anything is written:
 *
 *  - `one_time` must name the period it pays in, and `monthly` must not.
 *    An unanchored one-time allowance silently lands in whichever month is
 *    processed next, which is exactly the sort of figure a payroll review
 *    cannot reconcile - so it is refused at the door rather than tolerated.
 *
 *  - `effective_to` cannot precede `effective_from`. A window that ends
 *    before it starts pays for nothing while appearing to be configured.
 *
 * `status` is editable (active/cancelled) rather than only settable at
 * creation, because "stop this allowance" should not require deleting a row
 * that may be mid-calculation.
 *
 * `employee_id` is *not* accepted on update: moving an allowance to a
 * different person would restate history they did not agree to.
 */
class StoreAllowanceRequest extends FormRequest
{
    /**
     * Update asks for a different ability than create only when the policy
     * says so; both are `payroll.manage`, so the check is identical here.
     * Route-level `permission:` middleware runs first either way.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Allowance::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Not accepted on update: moving an allowance to a different
            // person would restate a figure they did not agree to, and a
            // `prohibited` rule says so with a proper 422 instead of
            // silently ignoring the field the client thought it had set.
            'employee_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'exists:employees,id'],

            'code' => ['required', 'string', 'max:40', 'in:'.implode(',', Allowance::KNOWN_CODES)],
            'label' => ['required', 'string', 'max:120'],

            // `numeric`, not `decimal:2` - an amount with more places is
            // accepted and then rounded once by Money::decimal() at the
            // boundary, rather than rejected for a difference the reader
            // would never see. What is refused is zero and negative.
            'amount' => ['required', 'numeric', 'gt:0', 'lte:999999999.99'],

            'frequency' => ['required', 'string', 'in:'.implode(',', Allowance::FREQUENCIES)],

            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],

            'payroll_year' => ['nullable', 'integer', 'min:2000', 'max:3000'],
            'payroll_month' => ['nullable', 'integer', 'min:1', 'max:12'],

            'status' => ['sometimes', 'string', 'in:'.implode(',', Allowance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Nothing to reconcile when the field was not sent. On create
            // `frequency` is required so this never skips there; on update
            // it is `sometimes`, and a body that does not mention it is not
            // making a claim about one_time versus monthly.
            if (! $this->filled('frequency')) {
                return;
            }

            $frequency = (string) $this->input('frequency');
            $year = $this->input('payroll_year');
            $month = $this->input('payroll_month');

            if ($frequency === Allowance::FREQUENCY_ONE_TIME) {
                if ($year === null || $month === null) {
                    $validator->errors()->add(
                        'payroll_month',
                        'A one-time allowance must name the payroll period it belongs to.',
                    );
                }

                if ((int) $month < 1 || (int) $month > 12) {
                    $validator->errors()->add('payroll_month', 'The month must be between 1 and 12.');
                }

                return;
            }

            if ($year !== null || $month !== null) {
                $validator->errors()->add(
                    'payroll_month',
                    'A monthly allowance is not tied to one period - leave the year and month empty.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Choose the employee this allowance belongs to.',
            'employee_id.exists' => 'That employee does not exist.',
            'code.in' => 'Choose one of the standard allowance codes: housing, transport, food, site, other.',
            'label.required' => 'Give the allowance a name the payslip can print.',
            'amount.gt' => 'An allowance amount must be greater than zero.',
            'amount.lte' => 'An allowance amount cannot exceed 999999999.99.',
            'frequency.in' => 'Frequency must be monthly or one_time.',
            'effective_to.after_or_equal' => 'The allowance cannot end before it starts.',
        ];
    }
}
