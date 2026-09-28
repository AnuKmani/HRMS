<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/loans/{loan}/{submit|approve|reject|cancel}
 *
 * One request object for all four transitions, because none of them takes
 * anything but a remark - the same shape and the same reasoning as
 * ActOnLeaveRequest.
 *
 * What they may *do* is the policy's question ("is this yours?", "are you
 * the approver?", "is it still pending?") asked by the controller where the
 * model is already resolved, and the service's question ("does that
 * transition exist from here?") answered as a 409 that names the state.
 * Neither is asked of this body.
 *
 * `remarks` is optional: a rejection usually deserves a reason while a
 * submission never has one, and requiring it on all four would either force
 * an empty string or block the transitions that need no words.
 */
class ActOnLoan extends FormRequest
{
    public function authorize(): bool
    {
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
