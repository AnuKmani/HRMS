<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/assets
 *
 * What a client may claim about a piece of company property.
 *
 * **`asset_code` is unique and is chosen once.** It is the asset's
 * permanent identity — what a barcode label, an audit and a hand-over log
 * will all read — so a second asset may not be given an existing one, and
 * the field is `required` rather than generated: the code is usually the
 * number already stickered on the thing.
 *
 * **`status` is `prohibited`.** Every asset is born `available`; where it
 * goes next is AssetService's answer, taken under a lock and checked
 * against Asset::TRANSITIONS. A `status` on create would be a way to
 * fabricate an asset that is already out with somebody and has never been
 * assigned.
 *
 * **`current_condition` is allowed on create and refused on update.** At
 * creation it is the honest answer to "what did it look like when we got
 * it"; afterwards it changes only through assign, return and changeStatus —
 * each of which records who, when and why — and allowing it in an edit
 * would be a back door past all three.
 *
 * `purchase_cost` is money: `numeric` with an upper bound that matches
 * DECIMAL(12,2) exactly, never a float, and never echoed to a reader
 * without `assets.manage`.
 */
class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('assets.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'asset_code' => [
                'required',
                'string',
                'max:60',
                Rule::unique('assets', 'asset_code'),
            ],

            'asset_type_id' => [
                'required',
                'integer',
                Rule::exists('asset_types', 'id')->where('status', 'active'),
            ],

            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],

            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],

            'current_condition' => ['sometimes', Rule::in(Asset::CONDITIONS)],
            'notes' => ['nullable', 'string', 'max:1000'],

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
            'purchase_cost.max' => 'That cost is larger than the register can hold.',
            'current_condition.in' => 'Condition must be new, good, fair or poor.',
            'status.prohibited' => 'A new asset is available; its status changes through assignment and status actions.',
        ];
    }
}
