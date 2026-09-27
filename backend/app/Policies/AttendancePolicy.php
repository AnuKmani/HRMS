<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Support\Visibility;

/**
 * Attendance: row-level, and unusually strict about it.
 *
 * Three things this policy is built around:
 *
 *  - **Checking in is not a permission.** `checkIn()` asks only "is this
 *    token attached to an employee?". Every ordinary person clocks in, and
 *    gating that behind `attendance.manage` would mean an employee could not
 *    record their own day. The route carries no `permission:` middleware for
 *    the same reason `GET /employees/{id}` does not — the policy is the
 *    boundary.
 *
 *  - **Reading others' attendance is not implied by reading your own.**
 *    `view()` defers to Visibility::attendanceIsVisible(), which fails
 *    closed: an employee holding nothing but `attendance.view` gets their own
 *    rows and no others, while HR and the field roles get theirs scoped to
 *    the projects and sites they actually run.
 *
 *  - **Nobody edits attendance.** There is no `update()` and no `delete()`.
 *    Phase 5 has no manual override, and adding an empty ability now would
 *    only invite a caller to depend on it. When the override lands it will
 *    arrive with its own ability, its own service method and its own audit
 *    entry — never as a hole in this file.
 */
class AttendancePolicy
{
    /**
     * Coarse gate for the collection: `attendance.view`.
     *
     * Granted to the ordinary Employee role on purpose — without it an
     * employee could not fetch their own history at all — which is exactly
     * why Visibility narrows the rows rather than trusting the permission.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.view');
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return Visibility::attendanceIsVisible($user, $attendance);
    }

    /**
     * Start the day. Identity, not privilege: any linked employee may clock
     * in for themselves, and the site, geofence and shift rules are what
     * decide whether the clock-in is any good.
     */
    public function checkIn(User $user): bool
    {
        return $user->employee !== null;
    }

    /**
     * Finish the day — same question, same answer. Whether there is
     * anything open to finish is AttendanceService's business, not a
     * permission's.
     */
    public function checkOut(User $user): bool
    {
        return $user->employee !== null;
    }

    /**
     * `GET /attendance/today`. Reachable without `attendance.view` because
     * the screen is the employee's own front door; everything it returns
     * about the session is their own.
     */
    public function today(User $user): bool
    {
        return $user->employee !== null;
    }

    /**
     * The selfie behind a row the caller may already read.
     *
     * Deliberately not a separate permission: there is no "may see photos"
     * grant to hold, so the only question is "may you see this attendance
     * record?" — and a photograph of somebody standing at a site is not less
     * sensitive than the record it is attached to. An employee can fetch
     * their own; a supervisor can fetch the rows on their sites; nobody
     * browses.
     */
    public function viewSelfie(User $user, Attendance $attendance): bool
    {
        return Visibility::attendanceIsVisible($user, $attendance);
    }
}
