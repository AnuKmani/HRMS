<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\LeaveRequest;

/**
 * PUT /api/v1/leave/{leaveRequest}
 *
 * Same body as creating, because editing a draft *is* creating it — just
 * later. Two differences: a PUT may carry only the fields it wants to change,
 * and authorisation needs the record — only a draft may be edited, and only
 * by its owner or by HR.
 */
class UpdateLeaveRequest extends StoreLeaveRequest
{
    use MakesRequiredRulesOptional;

    public function authorize(): bool
    {
        $leave = $this->route('leaveRequest');

        if (! $leave instanceof LeaveRequest) {
            // Route model binding did not resolve — fall back to the create
            // ability rather than answering `true`, so a malformed route can
            // never grant more than the caller already holds.
            return parent::authorize();
        }

        return $this->user()?->can('update', $leave) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->relaxRequired(parent::rules());
    }
}
