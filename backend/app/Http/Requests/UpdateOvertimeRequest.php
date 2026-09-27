<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\OvertimeRequest;

/**
 * PUT /api/v1/overtime/{overtimeRequest}
 *
 * Same table as creating, relaxed to `sometimes`. The instance-level rule —
 * a draft only, and only yours or HR's — lives in OvertimeRequestPolicy, which is
 * asked before this form ever validates.
 */
class UpdateOvertimeRequest extends StoreOvertimeRequest
{
    use MakesRequiredRulesOptional;

    public function authorize(): bool
    {
        $overtime = $this->route('overtimeRequest');

        if (! $overtime instanceof OvertimeRequest) {
            return parent::authorize();
        }

        return $this->user()?->can('update', $overtime) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->relaxRequired(parent::rules());
    }
}
