<?php

namespace App\Events;

use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An overtime request reached a terminal answer: approved or rejected.
 *
 * The overtime twin of {@see LeaveDecided}, and for the same reason it
 * exists rather than being folded into one event: overtime's payload, its
 * actor and its subject type are all different, and a listener that had to
 * `instanceof` its way through a generic "something was decided" event
 * would be where the next bug lives.
 *
 * Raised when the workflow chain closes, not when an intermediate step
 * advances — the requester is told once, with the answer, not once per
 * approver.
 */
class OvertimeDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly OvertimeRequest $overtimeRequest,
        public readonly string $decision,
        public readonly ?User $actor = null,
    ) {}
}
