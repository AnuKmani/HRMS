<?php

namespace App\Policies;

use App\Models\SiteVisit;
use App\Models\User;
use App\Support\Visibility;

/**
 * Site visits mirror attendance exactly, because they are the same act seen
 * from a different angle: a bounded record of where somebody deliberately
 * was, visible to themselves and to the people who run that ground.
 *
 * `end()` is narrower than `view()` on purpose — a supervisor may read a
 * visit on their site, but only the person who started it may close it.
 * Letting a manager end somebody else's visit would put an `ended_at` on a
 * record that no longer described anything that happened.
 */
class SiteVisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.view');
    }

    public function view(User $user, SiteVisit $siteVisit): bool
    {
        return Visibility::attendanceIsVisible($user, $siteVisit);
    }

    public function start(User $user): bool
    {
        return $user->employee !== null;
    }

    /**
     * `GET /site-visits/today`. Own visits only, no coarse gate — the same
     * reasoning as AttendancePolicy::today().
     */
    public function today(User $user): bool
    {
        return $user->employee !== null;
    }

    public function end(User $user, SiteVisit $siteVisit): bool
    {
        return $user->employee !== null
            && (int) $user->employee->id === (int) $siteVisit->employee_id;
    }
}
