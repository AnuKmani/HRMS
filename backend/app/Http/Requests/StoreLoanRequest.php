<?php

namespace App\Http\Requests;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST|PUT /api/v1/loans
 *
 * What a client may claim about a loan or a salary advance, and one rule
 * that only exists because of how the schedule is built.
 *
 * `employee_id` is not accepted on update for the same reason it is not
 * accepted on an allowance update: re-pointing a debt at a different person
 * rewrites history they did not agree to, and `prohibited` says so with a
 * proper 422 rather than silently ignoring the field.
 *
 * **The installment rule.** LoanService mints `n` installments as
 * `n-1` full payments plus a remainder, so `installment_amount` has to leave
 * something for the last one. If it does not, the schedule's final row would
 * be zero or negative - a loan that can never reach `completed`, because
 * `completed` is decided by the balance hitting zero. Refusing here means
 * the client is told which number to change instead of discovering a broken
 * repayment plan three months in.
 *
 * The rule is skipped when `installment_amount` is absent, because the
 * service then divides the principal evenly rather than trusting a figure
 * the client did not supply - and an even split always leaves a positive
 * remainder.
 *
 * No `outstanding_balance` and no `status`: the first is owned by
 * LoanService and the second is the lifecycle, neither of which a client
 * gets to assert.
 */
class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Loan::class) ?? false;
    }

    /**
     * Fills `employee_id` from the caller when the body does not name one.
     *
     * "Borrowing for myself" is the overwhelming case and the same default
     * `SalaryCertificateRequestController::store` already makes, for the same
     * reason: an employee should never have to know their own row id to ask
     * for an advance. It runs before authorization, which is fine — the
     * route's `permission:loans.create` middleware has already authenticated
     * the caller by then — and it deliberately does **not** fill anything on
     * PUT, where `employee_id` is `prohibited` outright.
     *
     * An account with no employee row is left untouched so that the `required`
     * rule below can say so, rather than quietly writing `employee_id = 0`
     * into a foreign key.
     */
    public function prepareForValidation(): void
    {
        if ($this->isMethod('POST') && ! $this->filled('employee_id')) {
            $own = $this->user()?->employee?->id;

            if ($own !== null) {
                $this->merge(['employee_id' => $own]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'exists:employees,id'],

            'loan_type' => ['sometimes', 'string', 'in:'.implode(',', Loan::TYPES)],
            'reference' => ['nullable', 'string', 'max:40'],

            'principal_amount' => ['required', 'numeric', 'gt:0', 'lte:999999999.99'],

            'installment_amount' => ['nullable', 'numeric', 'gt:0', 'lte:999999999.99'],

            'number_of_installments' => ['required', 'integer', 'gte:1', 'lte:600'],

            // `date_format`, not `date`: "2026-02-30" passes Laravel's `date`
            // rule and would silently become 2 March when normalised, which
            // is a repayment schedule nobody agreed to.
            'start_date' => ['required', 'date_format:Y-m-d'],

            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Whose debt is this? A loan is only ever asked for by the
            // person who owes it, unless the caller may manage loans for
            // everybody - the same rule StoreSalaryCertificateRequest
            // applies, and for the same reason: `loans.create` means "I may
            // borrow", not "I may borrow on somebody else's behalf".
            $employeeId = $this->input('employee_id');

            if ($employeeId !== null && $employeeId !== '') {
                $own = $this->user()?->employee?->id;

                if ((int) $employeeId !== (int) $own
                    && ! $this->user()?->can('loans.manage')
                ) {
                    $validator->errors()->add(
                        'employee_id',
                        'You may only ask for a loan on your own behalf.',
                    );
                }
            }

            if (! $this->filled('installment_amount')) {
                return;
            }

            $principal = (float) $this->input('principal_amount');
            $each = (float) $this->input('installment_amount');
            $count = (int) $this->input('number_of_installments');

            if ($count > 1 && $each * ($count - 1) >= $principal) {
                $validator->errors()->add(
                    'installment_amount',
                    sprintf(
                        'At %d installments, %s each would finish the %s principal before the last payment. '
                        .'Lower the installment amount or take fewer of them.',
                        $count,
                        number_format($each, 2, '.', ''),
                        number_format($principal, 2, '.', ''),
                    ),
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
            'employee_id.required' => 'Choose the employee this loan belongs to - an account with no employee record cannot borrow.',
            'employee_id.exists' => 'That employee does not exist.',
            'loan_type.in' => 'Loan type must be loan or salary_advance.',
            'principal_amount.required' => 'Enter the amount advanced.',
            'principal_amount.gt' => 'A loan principal must be greater than zero.',
            'principal_amount.lte' => 'A loan principal cannot exceed 999999999.99.',
            'installment_amount.gt' => 'An installment must be greater than zero.',
            'number_of_installments.required' => 'Say how many installments repay this.',
            'number_of_installments.gte' => 'A loan needs at least one installment.',
            'number_of_installments.lte' => 'A loan cannot exceed 600 installments.',
            'start_date.required' => 'Choose the date the first installment falls due.',
            'start_date.date_format' => 'The start date must be in YYYY-MM-DD form.',
        ];
    }
}
