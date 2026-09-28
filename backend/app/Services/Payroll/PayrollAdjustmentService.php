<?php

namespace App\Services\Payroll;

use App\Models\PayrollAdjustment;
use App\Models\User;

/**
 * The decision half of a payroll adjustment: approve, reject, cancel.
 *
 * Create and edit stay in the controller, matching how master data is
 * written elsewhere in this application - they change a row and nothing
 * else. What lives here is the part that changes a row's *meaning*: the
 * moment a bonus stops being a proposal and becomes something a pay run
 * will pick up, and the two ways that moment can be refused.
 *
 * State conflicts are 409s naming the state, permission failures are 403s
 * from PayrollAdjustmentPolicy. The policy says who may act; this class says
 * whether the transition exists from here - and it is asked twice because a
 * rule that only the controller enforces stops being a rule the first time
 * something else calls the service.
 */
final class PayrollAdjustmentService
{
    public function approve(
        PayrollAdjustment $adjustment,
        User $actor,
        ?string $remarks = null,
    ): PayrollAdjustment {
        if ($adjustment->status !== PayrollAdjustment::STATUS_PENDING) {
            abort(409, 'Only a pending adjustment can be approved. This one is '.$adjustment->status.'.');
        }

        $adjustment->status = PayrollAdjustment::STATUS_APPROVED;
        $adjustment->approved_by = $actor->id;
        $adjustment->approved_at = now();

        if ($remarks !== null && $remarks !== '') {
            $adjustment->remarks = $remarks;
        }

        $adjustment->save();

        return $adjustment;
    }

    public function reject(
        PayrollAdjustment $adjustment,
        User $actor,
        ?string $remarks = null,
    ): PayrollAdjustment {
        if ($adjustment->status !== PayrollAdjustment::STATUS_PENDING) {
            abort(409, 'Only a pending adjustment can be rejected. This one is '.$adjustment->status.'.');
        }

        $adjustment->status = PayrollAdjustment::STATUS_REJECTED;
        $adjustment->rejected_at = now();

        if ($remarks !== null && $remarks !== '') {
            $adjustment->remarks = $remarks;
        }

        $adjustment->save();

        return $adjustment;
    }

    /**
     * Withdraw - available from any state that is not already terminal,
     * because an approved-but-not-yet-paid bonus is the one a correction is
     * most likely to target, and refusing it there would mean the only way
     * to stop a mistaken bonus was to wait for the run to take it.
     */
    public function cancel(
        PayrollAdjustment $adjustment,
        ?string $remarks = null,
    ): PayrollAdjustment {
        if ($adjustment->status === PayrollAdjustment::STATUS_CANCELLED) {
            abort(409, 'This adjustment is already cancelled.');
        }

        if ($adjustment->status === PayrollAdjustment::STATUS_REJECTED) {
            abort(409, 'A rejected adjustment cannot be cancelled - it was never going to be paid.');
        }

        $adjustment->status = PayrollAdjustment::STATUS_CANCELLED;
        $adjustment->cancelled_at = now();

        if ($remarks !== null && $remarks !== '') {
            $adjustment->remarks = $remarks;
        }

        $adjustment->save();

        return $adjustment;
    }

    /**
     * Edit - pending only. Anything already approved is a decision somebody
     * made about a specific figure, and changing it afterwards would leave
     * `approved_by` next to numbers the approver never saw.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PayrollAdjustment $adjustment, array $data): PayrollAdjustment
    {
        if ($adjustment->status !== PayrollAdjustment::STATUS_PENDING) {
            abort(409, 'Only a pending adjustment can be edited. This one is '.$adjustment->status.'.');
        }

        $adjustment->fill($data);
        $adjustment->save();

        return $adjustment;
    }
}
