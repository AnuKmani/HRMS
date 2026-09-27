<?php

namespace App\Events;

use App\Models\LeaveRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A sick-leave request was converted to Loss of Pay because its medical
 * certificate never arrived by the deadline.
 *
 * **This is a hook, not a notification.** Nothing in Phase 6 listens to it.
 * The audit phase will attach a log entry and the notification phase will
 * attach "tell the employee, tell HR" — and neither will have to touch
 * LeaveRequestService to do it.
 *
 * Deliberately not broadcast, not queued, and carrying no recipient list:
 * this class records that something happened and hands the subject over.
 * Inventing a delivery channel here would be exactly the fake notification
 * the specification rules out (scope item Y).
 *
 * ShouldDispatchAfterCommit rather than plain Dispatchable: a listener that
 * read the row while the converting transaction was still open would see the
 * pre-conversion status, and an event describing something that has not
 * happened yet is worse than no event at all.
 */
class LeaveConvertedToLop implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly LeaveRequest $leaveRequest) {}
}
