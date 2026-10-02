<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A leave request reached a terminal answer: approved or rejected.
 *
 * The decision, the subject and **who made it** — the actor is carried
 * because "your leave was approved" is a message nobody should have to
 * guess the author of, and because an approval nobody can attribute is an
 * audit entry with a hole in it.
 *
 * `decision` is `approved` or `rejected`, matching the status the service
 * just wrote, so a listener never has to re-derive it from the model and
 * risk disagreeing with the row it is describing.
 *
 * Raised only when the chain is *finished*. An intermediate approval that
 * simply hands the request to the next approver is not a decision about
 * anybody's holiday, and the person whose request it is would be told
 * "approved" three times before anybody had finished reading it.
 */
class LeaveDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly string $decision,
        public readonly ?User $actor = null,
    ) {}
}
