<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/assets/{asset}/status
 *
 * Move an asset's lifecycle status — maintenance, damaged, lost, retired.
 *
 * **`status` is required *and* it is still checked against the world.**
 * `Rule::in(Asset::STATUSES)` rejects a word that is not a status at all;
 * Asset::TRANSITIONS, consulted by AssetService inside a lock, rejects a
 * word that *is* one but is not reachable from where the asset currently
 * is. Two different questions: "is that a real status?" and "may this asset
 * become it?". Answering the first here and the second there is why the
 * refusal a caller gets names the state rather than a field.
 *
 * **`assigned` and `available` are refused by the service with their own
 * sentences.** Handing an asset to somebody is `assign`; bringing it back
 * is `return`. Both close or open an assignment row as well as touching the
 * asset, so letting them arrive as a status change would produce an asset
 * nobody could assign (an active hand-over) and nobody could return (no
 * longer marked assigned). The map in Asset::TRANSITIONS does not even list
 * them; the service refuses them with a sentence that names the right
 * endpoint instead.
 *
 * **`current_condition` and `notes` ride along** because a status change is
 * normally *about* something — the tool came back damaged, it was written
 * off — and making that two requests would guarantee the two disagree.
 * Condition is still constrained to the four values; it is not a back door
 * past `update`, because the only way to reach it is through an act that
 * already records who, when and why.
 *
 * `asset_code` is prohibited outright: an asset's identity is not something
 * a status change should be able to rewrite, and the register's codes are
 * what every hand-over in the history points back at.
 */
class ChangeAssetStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $asset = $this->route('asset');

        if ($user === null || ! $asset instanceof Asset) {
            return false;
        }

        return $user->can('changeStatus', $asset);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Asset::STATUSES)],
            'current_condition' => ['sometimes', Rule::in(Asset::CONDITIONS)],
            'notes' => ['nullable', 'string', 'max:1000'],

            'asset_code' => ['prohibited'],
            'asset_type_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Status must be available, assigned, maintenance, damaged, lost or retired.',
            'current_condition.in' => 'Condition must be new, good, fair or poor.',
            'asset_code.prohibited' => 'An asset\'s code never changes.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        return $this->validated();
    }
}
