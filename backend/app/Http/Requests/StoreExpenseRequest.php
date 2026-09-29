<?php

namespace App\Http\Requests;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Site;
use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/expenses
 *
 * What a client may claim about an expense, and the two rules that are not
 * about fields at all.
 *
 * **The claim is never about anyone else.** `employee_id` is `prohibited`
 * rather than ignored: the server derives the claimant from the bearer token
 * (ExpenseService::employeeFor()), and a payload that names a colleague
 * would otherwise be believed to be some kind of "file for somebody else"
 * feature, which is not one this system has. `status` is refused for the
 * same reason in the opposite direction — the lifecycle is five service
 * methods, and `prohibited` says "this endpoint never accepts it" with a
 * proper 422 rather than silently dropping a field the client believed it
 * had set.
 *
 * **A site belongs to a project.** Half of `withValidator()` is a
 * cross-field rule no single `exists:` rule can express: a site id that
 * exists is not the same claim as "this site is in the project you picked",
 * and an expense pointing at a site from an unrelated project is exactly
 * the arbitrary linkage the API refuses to write.
 *
 * `before_or_equal:today` on the date, because a claim is money already
 * spent. A future date is not an expense, it is a plan, and accepting one
 * would let a claim sit in an approver's queue for a month before anything
 * was bought.
 *
 * Whether this person may book against *that* project or site — a posting,
 * a project they run, or `expenses.manage` — is not a field question and is
 * answered in ExpenseService through Visibility::mayClaimExpenseAt(), which
 * asks it on create, on update and again at submit.
 *
 * **The currency is configured, not assumed.** The code a claim is filed in
 * comes from `system.supported_currencies` (see allowedCurrencies()) and the
 * default the form offers comes from `system.currency` — both settings, both
 * readable at `GET /api/v1/client-settings`. Three letters is the shape of an
 * ISO code; whether *this* company accepts that code is a rule only the
 * server knows, which is why the membership check lives here and not in the
 * Flutter form. `prepareForValidation()` upper-cases it first so `aed` and
 * `AED` are the same claim rather than one passing and one being refused.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Expense::class) ?? false;
    }

    /**
     * Currency is upper-cased before any rule sees it, so the three-letter
     * shape rule and the supported-code rule answer the same question about
     * the same spelling. ExpenseService upper-cases it again on the way into
     * the row; this is for the validator, not for storage.
     */
    public function prepareForValidation(): void
    {
        if ($this->filled('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }

    /**
     * The currency codes a claim may be filed in, per
     * `system.supported_currencies`.
     *
     * An empty (or absent) list means the membership rule is switched off
     * rather than every claim refused: a setting an operator has not filled
     * in is not a reason to stop work, it is a reason to fall back to the
     * three-letter shape rule alone.
     *
     * @return array<int, string>
     */
    protected function allowedCurrencies(): array
    {
        $codes = [];

        foreach (app(SettingsService::class)->json('system.supported_currencies') as $code) {
            if (! is_string($code) || trim($code) === '') {
                continue;
            }

            $codes[] = strtoupper(trim($code));
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $allowed = $this->allowedCurrencies();

        return [
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],

            // Active on the way in as well as in the service: a retired
            // category should not be selectable from a form that still has
            // it in a cached list.
            'expense_category_id' => [
                'required',
                'integer',
                Rule::exists('expense_categories', 'id')->where('status', ExpenseCategory::STATUS_ACTIVE),
            ],

            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],

            // DECIMAL(12,2) is the column, so the ceiling is the largest
            // value that column can hold rather than a number somebody chose.
            'amount' => ['required', 'numeric', 'gt:0', 'lte:99999999.99'],

            'currency' => array_filter([
                'required',
                'string',
                'size:3',
                'alpha',
                $allowed === [] ? null : Rule::in($allowed),
            ]),

            'description' => ['required', 'string', 'min:3', 'max:500'],

            'employee_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('site_id')) {
                return;
            }

            if (! $this->filled('project_id')) {
                $validator->errors()->add(
                    'project_id',
                    'Choose the project this site belongs to.',
                );

                return;
            }

            $site = Site::query()->find($this->input('site_id'));

            if ($site !== null && (int) $site->project_id !== (int) $this->input('project_id')) {
                $validator->errors()->add(
                    'site_id',
                    'That site does not belong to the selected project.',
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
            'expense_date.required' => 'Choose the date the expense was incurred.',
            'expense_date.date_format' => 'The expense date must be in YYYY-MM-DD form.',
            'expense_date.before_or_equal' => 'An expense cannot be dated in the future — it is money already spent.',
            'expense_category_id.required' => 'Choose an expense category.',
            'expense_category_id.exists' => 'That expense category does not exist or is no longer available.',
            'project_id.exists' => 'That project does not exist.',
            'site_id.exists' => 'That site does not exist.',
            'amount.required' => 'Enter the amount spent.',
            'amount.gt' => 'An expense amount must be greater than zero.',
            'amount.lte' => 'An expense amount cannot exceed 99999999.99.',
            'currency.required' => 'Enter the currency — three letters, as in AED.',
            'currency.size' => 'A currency code is exactly three letters.',
            'currency.alpha' => 'A currency code is letters only.',
            'currency.not_in' => 'This company does not accept claims filed in that currency.',
            'description.required' => 'Say what this expense was for.',
            'description.min' => 'Describe the expense in at least three characters.',
            'description.max' => 'Keep the description to 500 characters.',
            'employee_id.prohibited' => 'The claim is filed for the signed-in employee; employee_id is never accepted.',
            'status.prohibited' => 'Status is set by submitting, approving or cancelling — never by the client.',
        ];
    }
}
