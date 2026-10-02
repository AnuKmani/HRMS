<?php

namespace App\Events;

use App\Models\EmployeeSiteAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An employee was pointed at a site.
 *
 * The event exists because "you have been assigned to Site X" is asked for
 * by three different parts of the system — the in-app inbox, the push
 * channel, and (later) the daily digest — and none of them should be
 * reaching back into `EmployeeSiteAssignmentService` to find out when it
 * happened.
 *
 * Carries the assignment rather than the site: the dates, the role and the
 * employee all live on that row, and a listener that needed any of them
 * can read them without a second query.
 */
class SiteAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EmployeeSiteAssignment $assignment) {}
}
