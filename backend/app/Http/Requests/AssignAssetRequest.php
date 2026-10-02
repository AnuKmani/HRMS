<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorAlias;

/**
 * POST /api/v1/assets/{asset}/assign
 *
 * Hand an asset to somebody.
 *
 * **Everything about *who* is decided here; everything about *whether it is
 * possible* is AssetService's.** `employee_id`, the two dates and a
 * condition are payload facts and get `exists` / `date` rules. Whether the
 * asset is available, and whether a second hand-over would collide with one
 * already in flight, are 409s from inside AssetService's transaction —
 * because the second is a fact about a row nobody can see until the lock
 * is taken, and a validation rule that looked before locking would be
 * agreeing with a snapshot rather than with the world.
 *
 * **The employee must exist, and 403 covers both "there is no such person"
 * and "you may not assign to this one"** — the same walk-the-directory
 * argument StoreEmployeeTrainingRequest makes, one request over.
 *
 * **`assigned_condition` defaults to the asset's own condition.** The
 * condition at hand-out is *usually* the condition it is in, and a form
 * that has to retype it will eventually record a guess instead of a fact.
 * When it *is* supplied and differs, that difference is exactly the thing a
 * hand-over exists to notice.
 *
 * `status` and `returned_*` are `prohibited` rather than dropped: a
 * hand-back is a different action on a different day, and silently ignoring
 * a `returned_date` a client believed it had set is how an API ends up with
 * a caller that thinks it closed a hand-over.
 */
class AssignAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $asset = $this->route('asset');

        if ($user === null || ! $asset instanceof Asset) {
            return false;
        }

        return $user->can('assign', $asset);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],

            'assigned_date' => ['sometimes', 'date'],
            'expected_return_date' => ['nullable', 'date', 'after_or_equal:assigned_date'],

            'assigned_condition' => ['sometimes', Rule::in(Asset::CONDITIONS)],
            'remarks' => ['nullable', 'string', 'max:1000'],

            'status' => ['prohibited'],
            'returned_date' => ['prohibited'],
            'returned_condition' => ['prohibited'],
            'returned_by' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Select a valid employee.',
            'expected_return_date.after_or_equal' => 'The expected return date cannot be before the assignment date.',
            'assigned_condition.in' => 'Condition must be new, good, fair or poor.',
            'status.prohibited' => 'A hand-over opens the assignment; its status is set by the server.',
            'returned_date.prohibited' => 'A return is a separate action, taken on the day it happens.',
            'returned_condition.prohibited' => 'A return is a separate action, taken on the day it happens.',
        ];
    }

    public function withValidator(ValidatorAlias $validator): void
    {
        // Resolved once here so `authorize()` and the controller's payload
        // helper agree on which employee was meant even when the id was
        // malformed rather than merely absent.
        $validator->after(function (ValidatorAlias $validator) {
            $id = $this->input('employee_id');

            if ($id === null || $id === '' || $validator->errors()->has('employee_id')) {
                return;
            }

            if (! is_scalar($id) || ! is_numeric((string) $id)) {
                $validator->errors()->add('employee_id', 'Select a valid employee.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function assignmentPayload(): array
    {
        return $this->validated();
    }
}
