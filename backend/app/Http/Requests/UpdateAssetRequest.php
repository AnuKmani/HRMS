<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/assets/{asset}
 *
 * Correct an asset's master record.
 *
 * **What this may touch is deliberately narrower than what it may not.**
 * Name, serial, model, manufacturer, purchase details, description and notes
 * are all editable — those are facts somebody can get wrong when the asset
 * is registered. Condition and status are not, and neither are they merely
 * filtered: both are `prohibited`, so a client that believed it had set one
 * gets a 422 naming the field rather than a 200 and a lie.
 *
 * They change through assign, return and changeStatus instead, each of which
 * records who and when. An update that accepted `status` would be a back
 * door past all three — and past Asset::TRANSITIONS, which is the only
 * thing that says whether an available laptop may become a retired one.
 *
 * **A retired asset cannot be edited at all**, and that refusal is
 * AssetService's 409 rather than a 403 here: the permission is fine, the
 * *state* is wrong, and "that asset has been written off" is a sentence
 * about the world rather than about the caller.
 *
 * `asset_code` may be corrected while the asset is still in service — a
 * mis-transcribed sticker on registration is a real thing — with a unique
 * rule that ignores the row being edited, because `Rule::unique()->ignore()`
 * is the whole of the difference between "already taken by another asset"
 * and "still yours".
 */
class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $asset = $this->route('asset');

        if ($user === null || ! $asset instanceof Asset) {
            return false;
        }

        return $user->can('update', $asset);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Asset|null $asset */
        $asset = $this->route('asset');

        return [
            'asset_code' => [
                'sometimes',
                'string',
                'max:60',
                Rule::unique('assets', 'asset_code')->ignore($asset?->id),
            ],

            'asset_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('asset_types', 'id')->where('status', 'active'),
            ],

            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'current_condition' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'asset_code.unique' => 'That asset code is already in use.',
            'asset_type_id.exists' => 'Select an active asset type.',
            'current_condition.in' => 'Condition must be new, good, fair or poor.',
            'current_condition.prohibited' => 'Condition changes when an asset is assigned or returned, so it is recorded with the hand-over.',
            'status.prohibited' => 'Status changes through the assign, return and status actions.',
        ];
    }
}
