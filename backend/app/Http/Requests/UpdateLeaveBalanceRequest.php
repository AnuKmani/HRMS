<?php

namespace App\Http\Requests;

use App\Models\LeaveBalance;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/leave-balances/{leaveBalance}
 *
 * The three numbers an HR Admin may set by hand, and nothing else.
 *
 * `entitlement`, `carry_forward` and `adjustment` are the inputs to
 * LeaveBalanceService's formula. `used` and `pending` are deliberately NOT
 * accepted here: they are derived from leave requests, and letting an
 * administrator type them in would let the ledger and the requests disagree
 * with no way to say which one is right. Read them, never write them.
 *
 * Bounds follow the columns rather than inventing a policy of their own:
 * entitlement and carry-forward are unsigned in the schema (they are amounts
 * of time granted), while adjustment is signed (it is a correction).
 *
 * There is no `reason` field. The migration's note on `adjustment` is
 * explicit that the audit phase attaches a rationale to that number — adding
 * a free-text field here would record one in a place no later report would
 * think to look.
 */
class UpdateLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $balance = $this->route('leaveBalance');

        if (! $balance instanceof LeaveBalance) {
            return false;
        }

        return $this->user()?->can('update', $balance) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entitlement' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'carry_forward' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'adjustment' => ['sometimes', 'integer', 'min:-365', 'max:365'],
        ];
    }

    public function messages(): array
    {
        return [
            'carry_forward.min' => 'Carried-forward days cannot be negative.',
            'adjustment.min' => 'An adjustment may not remove more than a year of entitlement.',
        ];
    }
}
