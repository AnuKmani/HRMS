<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;
use App\Support\Visibility;

/**
 * The holiday calendar is readable by everybody and writable by HR.
 *
 * Deliberately has no `holidays.view` permission behind `viewAny()`. A day the
 * company has declared off is information about the organisation rather than a
 * privilege within it, and gating it would mean an ordinary employee could not
 * answer "is Tuesday a public holiday?" before requesting leave — which the
 * leave screen needs to show them anyway.
 *
 * The row shape still constrains what a reader sees: `GET /holidays` returns
 * public holidays, company holidays, and site holidays for sites they are
 * assigned to. The collection's half of that lives in HolidayController's
 * query and the single-day half in [view()] through Visibility — one place
 * each, sharing holidaySiteIds(), so `show` cannot answer `index` a different
 * question.
 *
 * Writing is a different question with a different answer.
 */
class HolidayPolicy
{
    /**
     * Any authenticated user. Returns true unconditionally rather than
     * checking a permission that does not exist, so the absence reads as a
     * decision instead of an omission.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Holiday $holiday): bool
    {
        // Inactive days stay readable: a calendar that hid the holiday last
        // March would make last March's leave impossible to explain.
        //
        // Public and company days are open to any signed-in account for the
        // same reason `viewAny()` is. A *site* day is different: the index
        // hides days at sites the reader has nothing to do with, and opening
        // one by id must hide it too — otherwise the narrower of the two
        // endpoints is the one a stranger would find first.
        return Visibility::holidayIsVisible($user, $holiday);
    }

    public function create(User $user): bool
    {
        return $user->can('holidays.manage');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->can('holidays.manage');
    }

    /*
    | There is no `delete()` ability, and that is the point rather than an
    | omission. Removing a holiday would erase the reason last year's leave
    | excluded that date — the calendar's own `status = inactive` is how a day
    | is retired, and that is an `update`. Adding an empty ability now would
    | only invite a caller to depend on it; when a purge lands it will arrive
    | with its own rule, its own service method and its own audit entry.
    */
}
