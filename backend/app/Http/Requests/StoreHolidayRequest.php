<?php

namespace App\Http\Requests;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorAlias;

/**
 * POST|PUT /api/v1/holidays
 *
 * Three scopes on one form, because that is how the calendar is stored:
 * `public` and `company` carry no site, `site` requires one.
 *
 * Two rules are deliberately NOT here:
 *
 *  - **duplicate detection.** `Rule::unique` builds `site_id = ?`, and a
 *    public holiday has `site_id IS NULL`, where SQL equality is never true —
 *    so the rule would pass a second copy of the same public holiday while
 *    appearing to guarantee it cannot. HolidayController checks with a real
 *    `whereNull`, which is an honest check.
 *
 *  - **a `required_if` rule for the site.** Laravel's `nullable` short-circuits
 *    the remaining rules of an attribute whose value is null, which is
 *    precisely the case `required_if` needs to fire in. The requirement is
 *    therefore an `after()` check, where it runs unconditionally and reports
 *    on the field that is actually missing.
 */
class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Holiday::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date_format:Y-m-d'],

            'type' => ['required', Rule::in(Holiday::TYPES)],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],

            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(Holiday::STATUSES)],
        ];
    }

    /**
     * A site holiday is meaningless without a site — see the class note for
     * why this is not `required_if`.
     *
     * Falls back to the row being edited when a PUT does not mention `type`,
     * so narrowing an existing site holiday to `site_id = null` is caught even
     * though the payload never said the word "site".
     */
    public function withValidator(ValidatorAlias $validator): void
    {
        $validator->after(function (ValidatorAlias $validator) {
            $existing = $this->route('holiday');
            $type = $this->input('type')
                ?? ($existing instanceof Holiday ? $existing->type : null);

            if ($type === Holiday::TYPE_SITE && ! $this->filled('site_id')) {
                $validator->errors()->add('site_id', 'A site holiday needs a site.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'date.date_format' => 'Enter the holiday date as YYYY-MM-DD.',
        ];
    }
}
