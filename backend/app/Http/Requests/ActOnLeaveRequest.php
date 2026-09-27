<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/leave/{leaveRequest}/{submit|approve|reject|cancel}
 *
 * One request object for all four transitions, because none of them takes
 * anything but a remark. What they are allowed to *do* is decided by the
 * policy — may you, on this request, right now? — and by the service — is it
 * in a state where that transition exists? Not by anything in this body.
 *
 * `remarks` is optional because a rejection usually deserves a reason while a
 * submission never has one, and making it required on all four would either
 * force an empty string or block the cases that need no words.
 */
class ActOnLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Deliberately true. Every ability here needs the *instance* — "is
        // this yours?", "are you the current approver?" — and those are asked
        // of LeaveRequestPolicy by the controller, where the model is already
        // resolved. Answering them here too would put the same question in
        // two places with two chances to disagree.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
