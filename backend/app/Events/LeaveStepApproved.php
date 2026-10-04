<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One link of a leave chain said yes and the request moved on to the next
 * (e.g. the manager approved; now it is HR's turn). Not the final answer —
 * that is {@see LeaveDecided}.
 *
 * After commit, for the same reason as {@see LeaveSubmitted}: the listener
 * reads who holds the request now, which this transaction just changed.
 */
class LeaveStepApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly User $approvedBy,
    ) {}
}
