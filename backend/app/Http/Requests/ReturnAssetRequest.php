<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/assets/{asset}/return
 *
 * Take an asset back and record what came back.
 *
 * **`returned_condition` is required, and that is the point of the
 * endpoint.** Every other field here is a date or a note; this one is the
 * reason a return exists. A hand-back that did not state what condition the
 * thing came back in would leave "who had it" answered and "what happened
 * to it while they did" unanswered — and the server needs it anyway: it is
 * what decides whether the asset goes back on the shelf or straight to
 * maintenance.
 *
 * **`returned_date` defaults to today in the service**, because a return
 * recorded later than it happened is a fact about the *recording*, and the
 * case where somebody books in yesterday's hand-back still needs the
 * original date available rather than made impossible.
 *
 * **A second return and a return of something never assigned are both 409s
 * from AssetService**, not 422s from here. The payload is flawless; the
 * *world* is in the way — there is no active row to close — and a
 * validation error would tell the caller to fix a field that was never
 * wrong. The re-read under `lockForUpdate()` is what makes the second
 * return refused rather than merely unlikely: the first one flipped the row
 * to `returned` inside its own lock, so nothing active is left to find.
 *
 * `employee_id` is `prohibited` for the reason documents prohibit their
 * server-owned columns: this action is about *the asset's* current
 * hand-over, and accepting a person here would be a second, conflicting
 * answer to "whose is it?".
 */
class ReturnAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $asset = $this->route('asset');

        if ($user === null || ! $asset instanceof Asset) {
            return false;
        }

        return $user->can('returnAsset', $asset);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'returned_date' => ['sometimes', 'date'],
            'returned_condition' => ['required', Rule::in(Asset::CONDITIONS)],
            'remarks' => ['nullable', 'string', 'max:1000'],

            'employee_id' => ['prohibited'],
            'assigned_date' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'returned_condition.required' => 'Record the condition the asset came back in.',
            'returned_condition.in' => 'Condition must be new, good, fair or poor.',
            'employee_id.prohibited' => 'A return always applies to the asset\'s current hand-over.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function returnPayload(): array
    {
        return $this->validated();
    }
}
