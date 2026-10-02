<?php

namespace App\Events;

use App\Jobs\SendAttendanceReminders;
use App\Models\Employee;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody has not checked in for a shift that is about to start — or has
 * just started.
 *
 * Raised by {@see SendAttendanceReminders} on its schedule, never
 * by a controller: whether a person is late is a fact about the clock and
 * the roster, and a phone that is switched off must not be the thing that
 * decides whether it gets told.
 *
 * Carries the employee rather than the shift, because the listener needs
 * the employee anyway (that is who is notified) and the shift is a query
 * away on `currentSiteAssignment`. `ShouldDispatchAfterCommit` is a
 * courtesy rather than a necessity — the job writes nothing around it — but
 * it keeps this event indistinguishable from the eleven that do need it,
 * so a reader never has to work out which kind they are looking at.
 *
 * The message is suppressed once per person per day by the notification's
 * dedupe key, so an hourly schedule cannot become an hourly nag.
 */
class AttendanceReminder implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Employee $employee) {}
}
