<?php

namespace App\Http\Requests;

use App\Models\Loan;

/**
 * PUT /api/v1/loans/{loan}
 *
 * The same shape as creation minus the employee - re-pointing a debt at a
 * different person rewrites history they did not agree to - and with a
 * *different* ability, because "may ask for a loan" and "may correct this
 * draft" are not the same grant even though both resolve to `loans.*`.
 *
 * Whether *this* row may still be edited is LoanService's 409, not a
 * `prohibited_unless:status,draft` rule here: the message "Only a draft loan
 * can be edited. Cancel it and start again." tells the caller what to do,
 * where a state rule would only tell them the field was refused.
 */
class UpdateLoanRequest extends StoreLoanRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('loan')) ?? false;
    }

    /**
     * An update names what changed, not everything that is true - so every
     * field but the identity of the debtor becomes optional.
     *
     * `employee_id` stays `prohibited`: re-pointing a debt at a different
     * person is not "you may not change it this time", it is "this endpoint
     * never accepts it", and `prohibited` says so with a 422 rather than
     * silently ignoring a field the client believed it had set.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['prohibited', 'integer', 'exists:employees,id'],

            'loan_type' => ['sometimes', 'string', 'in:'.implode(',', Loan::TYPES)],
            'reference' => ['nullable', 'string', 'max:40'],

            'principal_amount' => ['sometimes', 'numeric', 'gt:0', 'lte:999999999.99'],
            'installment_amount' => ['nullable', 'numeric', 'gt:0', 'lte:999999999.99'],
            'number_of_installments' => ['sometimes', 'integer', 'gte:1', 'lte:600'],

            'start_date' => ['sometimes', 'date_format:Y-m-d'],

            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
