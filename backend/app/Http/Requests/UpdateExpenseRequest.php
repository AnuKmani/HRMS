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

            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],

            'description' => ['sometimes', 'string', 'min:3', 'max:500'],

            'employee_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
