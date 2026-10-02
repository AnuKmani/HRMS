<?php

namespace App\Events;

use App\Models\LeaveRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A sick-leave request is about to become Loss of Pay if its medical
 * certificate does not arrive.
 *
 * The warning shot in front of {@see LeaveConvertedToLop}: the same
 * request, a few days earlier, while there is still something the employee
 * can do about it. The conversion event says "this happened"; this one
 * says "this is going to happen".
 *
 * This is the only notification in the phase that is genuinely a
 * *reminder*, and it is mandatory — a person cannot switch off being told
 * their pay is about to be cut, which is exactly the sort of message
 * `NotificationCategory::MANDATORY` exists to protect.
 *
 * Raised from the scheduled sick-certificate deadline check, not from the
 * leave service: the deadline is a fact about the calendar, so the
 * reminder is too, and it belongs with the job that already owns that
 * clock.
 */
class SickCertificateReminder implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly LeaveRequest $leaveRequest) {}
}
