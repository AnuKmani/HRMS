<?php

namespace App\Policies;

use App\Models\DailySiteReport;
use App\Models\User;
use App\Support\Visibility;

/**
 * Daily site reports: the official site-day record, so authorship and
 * authority are what the row-level rule is made of.
 *
 * Three shapes of answer, in the order they are asked:
 *
 *  - **is it mine?** — `created_by`. A supervisor who prepared a document
 *    keeps it, even after they are moved to another site: losing access to
 *    a report you wrote because your posting changed would be a bug in
 *    record-keeping, not a security rule.
 *  - **am I scoped to its site or project?** — Site Supervisor, Site Engineer
 *    and Project Manager are narrowed to what they run (see
 *    config/hrms.php `visibility.attendance`), so their `show` cannot answer
 *    `index` differently.
 *  - **am I back-office?** — HR, Management and Super Admin read every
 *    report their permission admits, because a document about a site-day is
 *    exactly what an HR review reads.
 *
 * An Employee holds neither `daily_site_reports.view` nor `.create`, so the
 * first answer they can reach here is "none" — deliberately, because the
 * official record of a site-day being both unique and authoritative means
 * the whole company cannot be writing one.
 *
 * `pdf` is a separate ability from `view` rather than a consequence of it:
 * reading the numbers on a screen and being handed a document you can
 * forward are different acts, and a deployment that wants the first without
 * the second should not have to invent a role to get there.
 *
 * The name is `DailySiteReportPolicy` because Gate guesses the policy from
 * the model class — an unnamed policy is simply never consulted.
 */
class DailySiteReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('daily_site_reports.view');
    }

    public function view(User $user, DailySiteReport $dailySiteReport): bool
    {
        return Visibility::dailySiteReportIsVisible($user, $dailySiteReport);
    }

    public function create(User $user): bool
    {
        // No employee row, no site-day to describe. The *site* is checked in
        // DailySiteReportService against the payload, where a 403 can name
        // what was wrong with it.
        return $user->can('daily_site_reports.create') && $user->employee !== null;
    }

    public function update(User $user, DailySiteReport $dailySiteReport): bool
    {
        if (! $user->can('daily_site_reports.update')) {
            return false;
        }

        if ($dailySiteReport->created_by === $user->id) {
            return true;
        }

        if ($user->can('daily_site_reports.manage')) {
            return true;
        }

        if (! Visibility::attendanceIsScopedFor($user)) {
            return false;
        }

        $site = $dailySiteReport->site;

        return $site !== null && Visibility::mayReportAt($user, $site);
    }

    public function submit(User $user, DailySiteReport $dailySiteReport): bool
    {
        return $this->update($user, $dailySiteReport);
    }

    /**
     * May this report be turned into a PDF?
     *
     * Two questions, both required: the coarse `daily_site_reports.pdf`
     * grant, and the row question — a permission that lets you generate a
     * document for a report you may not otherwise open would be a hole with
     * a file extension.
     */
    public function pdf(User $user, DailySiteReport $dailySiteReport): bool
    {
        return $user->can('daily_site_reports.pdf')
            && Visibility::dailySiteReportIsVisible($user, $dailySiteReport);
    }
}
