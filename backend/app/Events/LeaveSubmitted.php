<?php

namespace App\Events;

use App\Models\LeaveRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A leave request left the drafts folder and entered an approval chain.
 *
 * Hook, not a notification — see {@see LeaveConvertedToLop} for why every
 * event in this phase is built that way. A listener turns this into "tell
 * whoever is holding it now"; this class only records that the request
 * went out and hands over the subject.
 *
 * After commit, because the chain that `ApprovalAudience` reads is written
 * inside the same transaction: a listener firing mid-transaction would
 * resolve an audience from the state before the request existed.
 */
class LeaveSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly LeaveRequest $leaveRequest) {}
}
