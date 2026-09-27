<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\LeaveType;

/**
 * PUT /api/v1/leave-types/{leaveType}
 *
 * Same table as creating, relaxed to `sometimes` so an integration may
 * deactivate a type without re-sending its whole entitlement schedule.
 */
class UpdateLeaveTypeRequest extends StoreLeaveTypeRequest
{
    use MakesRequiredRulesOptional;

    public function authorize(): bool
    {
        $leaveType = $this->route('leaveType');

        if (! $leaveType instanceof LeaveType) {
            return parent::authorize();
        }

        return $this->user()?->can('update', $leaveType) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->relaxRequired(parent::rules());
    }
}
