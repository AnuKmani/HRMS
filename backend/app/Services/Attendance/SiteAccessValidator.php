<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * May this person check in *here*? The second gate, after the geofence.
 *
 * The two answer different questions. The geofence says "is this device at
 * this address?"; this says "does this employee have a right to be at this
 * address at all?". Passing one says nothing about the other — a site's
 * geofence is public enough that a stranger standing inside it must still be
 * refused.
 *
 * Accepted paths to an authorised check-in, in order:
 *
 *   1. an assignment row for this site that is `active` and whose date range
 *      covers the day — primary, temporary or additional alike, because a
 *      fortnight's cover is as authorised as a permanent posting;
 *   2. `employees.primary_site_id` pointing at this site, for an employee
 *      whose site is set on their record before any assignment row exists.
 *
 * Path 2 deliberately does NOT apply when an assignment row exists but has
 * ended: a closed posting is an explicit "not any more", and falling back to
 * the profile field would silently override a decision somebody made. That
 * distinction is the whole reason both paths are written out here rather
 * than collapsed into a single `whereHas('assignments')`.
 */
final class SiteAccessValidator
{
    public function authorize(
        Employee $employee,
        Site $site,
        Carbon $onDate,
    ): SiteAccessDecision {
        if ($employee->employment_status !== Employee::STATUS_ACTIVE) {
            return SiteAccessDecision::deny(
                SiteAccessDecision::CODE_EMPLOYEE_INACTIVE,
                sprintf(
                    'Your employment status is "%s", so you cannot check in. Contact your administrator.',
                    $employee->employment_status,
                ),
            );
        }

        if ($site->status !== Site::STATUS_ACTIVE) {
            return SiteAccessDecision::deny(
                SiteAccessDecision::CODE_SITE_INACTIVE,
                'That site is not active, so check-in is not possible there.',
            );
        }

        $today = $onDate->toDateString();

        $rows = EmployeeSiteAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('site_id', $site->id)
            ->orderByDesc('start_date')
            ->get();

        if ($rows->isNotEmpty()) {
            $covering = $rows->first(fn (EmployeeSiteAssignment $row) => $this->covers($row, $today));

            if ($covering === null) {
                $mostRecent = $rows->first();

                return SiteAccessDecision::deny(
                    SiteAccessDecision::CODE_ASSIGNMENT_NOT_ACTIVE,
                    $mostRecent !== null && $mostRecent->end_date !== null
                        ? sprintf(
                            'Your assignment to this site ended on %s. Ask your supervisor to reassign you before checking in.',
                            $mostRecent->end_date->toDateString(),
                        )
                        : 'You have no active assignment to this site. Ask your supervisor to assign you before checking in.',
                );
            }

            // The assignment table carries both ends of the relationship and
            // insists they agree — this is the safety net if a site was ever
            // moved to another project underneath an existing posting.
            if ($covering->project_id !== $site->project_id) {
                return SiteAccessDecision::deny(
                    SiteAccessDecision::CODE_ASSIGNMENT_PROJECT_MISMATCH,
                    'Your assignment for this site belongs to a different project. Ask your administrator to correct it.',
                );
            }

            return SiteAccessDecision::allow(
                SiteAccessDecision::CODE_ASSIGNMENT,
                $covering->assignment_type,
                $covering,
            );
        }

        if ($employee->primary_site_id !== null && (int) $employee->primary_site_id === (int) $site->id) {
            return SiteAccessDecision::allow(SiteAccessDecision::CODE_PRIMARY_SITE, 'primary_site');
        }

        return SiteAccessDecision::deny(
            SiteAccessDecision::CODE_NOT_ASSIGNED,
            sprintf('You are not assigned to %s. Ask your supervisor to assign you before checking in.', $site->name),
        );
    }

    private function covers(EmployeeSiteAssignment $row, string $onDate): bool
    {
        if ($row->status !== EmployeeSiteAssignment::STATUS_ACTIVE) {
            return false;
        }

        if ($row->start_date !== null && $row->start_date->toDateString() > $onDate) {
            return false;
        }

        if ($row->end_date !== null && $row->end_date->toDateString() < $onDate) {
            return false;
        }

        return true;
    }
}
