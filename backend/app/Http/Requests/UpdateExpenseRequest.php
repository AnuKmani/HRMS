<?php

namespace App\Http\Requests;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/expenses/{expense}
 *
 * The same shape as creation, with every field optional because an update
 * names what changed rather than everything that is true — and `employee_id`
 * still `prohibited`, for the reason it always was: this endpoint never
 * re-points a claim at a different person, so the field is refused rather
 * than ignored.
 *
 * Whether *this* row may still be edited is ExpenseService's 409 ("Only a
 * draft claim can be edited"), not a state rule here: the message tells the
 * caller what to do instead of merely saying the field was refused.
 *
 * `expense_date` keeps `before_or_equal:today` under `sometimes` — a date
 * that is absent may be skipped, a date that is present still has to be
 * money already spent.
 */
class UpdateExpenseRequest extends StoreExpenseRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense
            ? ($this->user()?->can('update', $expense) ?? false)
            : false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'expense_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],

            'expense_category_id' => [
                'sometimes',
                'integer',
                Rule::exists('expense_categories', 'id')->where('status', ExpenseCategory::STATUS_ACTIVE),
            ],

            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],

            'amount' => ['sometimes', 'numeric', 'gt:0', 'lte:99999999.99'],

            'currency' => array_filter([
                'sometimes',
                'string',
                'size:3',
                'alpha',
                ($allowed = $this->allowedCurrencies()) === []
                    ? null
                    : Rule::in($allowed),
            ]),

            'description' => ['sometimes', 'string', 'min:3', 'max:500'],

            'employee_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * The supported list, plus whatever *this* claim was already filed in.
     *
     * The union matters for exactly one case: a claim whose currency is no
     * longer on the supported list — an operator narrowed it after the fact,
     * or the claim predates the setting. Such a claim must still be
     * correctable, or a person would be unable to fix a typo in their
     * description without first being told their own historical currency is
     * unsupported. Preserving what was stored is the point of a record; the
     * membership rule is about what may be *chosen*, and this endpoint is
     * not offering a choice.
     *
     * @return array<int, string>
     */
    protected function allowedCurrencies(): array
    {
        $allowed = parent::allowedCurrencies();

        // Nothing configured means nothing to be stricter than: creation
        // accepts any three-letter code here, so an update must not invent a
        // rule the create endpoint never had.
        if ($allowed === []) {
            return [];
        }

        $expense = $this->route('expense');

        if ($expense instanceof Expense && $expense->currency !== '') {
            $allowed[] = strtoupper((string) $expense->currency);
        }

        return array_values(array_unique($allowed));
    }
}
