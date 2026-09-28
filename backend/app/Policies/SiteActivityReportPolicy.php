<?php

namespace App\Policies;

use App\Models\SiteActivityReport;
use App\Models\User;
use App\Support\Visibility;

/**
 * Site activity reports: a personal note about a site-day, so the row-level
 * rule is "yours, or the one's of the site you run".
 *
 * The permission answers the coarse question (`site_activity_reports.view`
 * may this role open the module?) and Visibility answers the row question,
 * which is the same split leave, overtime and attendance use — one class
 * deciding both, so `index` and `show` cannot disagree about a row.
 *
 * `update` is narrower than it first looks. A supervisor correcting a
 * report filed at their own site is a supervisor doing their job; a
 * colleague with the same posting is a colleague, not a co-author. That
 * distinction is "is this a role that runs sites?" — attendanceIsScopedFor()
 * plus the explicit `daily_site_reports.manage` override — which is checked
 * before the site is, so an Employee on the same site never reaches
 * `mayReportAt()` at all.
 *
 * `employee_id` is never part of any answer here: the record belongs to
 * whoever's session created it, and no ability in this policy can move it
 * to somebody else.
 *
 * The name is `SiteActivityReportPolicy`, not `SitePolicy`, because Gate
 * guesses the class from the *model* — `App\Models\SiteActivityReport`
 * resolves to `App\Policies\SiteActivityReportPolicy` and nothing else. An
 * unnamed policy is never consulted, and every ability it defines quietly
 * answers "denied", which `route:list` will not tell you.
 */
class SiteActivityReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('site_activity_reports.view');
    }

    public function view(User $user, SiteActivityReport $siteActivityReport): bool
    {
        return Visibility::siteActivityReportIsVisible($user, $siteActivityReport);
    }

    public function create(User $user): bool
    {
        // An account with no employee row has no site-day to write about.
        // Which site they may write about is SiteActivityReportService's
        // question — it is asked with the payload, not with a class.
        return $user->can('site_activity_reports.create') && $user->employee !== null;
    }

    /**
     * Your own report, or one filed at a site you run.
     *
     * "Whether the *state* allows it" is the service's answer: this policy
     * decides who may try, and a second attempt on a submitted report reads
     * "Only a draft can be edited." as a 409 rather than a bare 403.
     */
    public function update(User $user, SiteActivityReport $siteActivityReport): bool
    {
        if (! $user->can('site_activity_reports.update')) {
            return false;
        }

        if ($this->owns($user, $siteActivityReport)) {
            return true;
        }

        if ($user->can('daily_site_reports.manage')) {
            return true;
        }

        if (! Visibility::attendanceIsScopedFor($user)) {
            return false;
        }

        $site = $siteActivityReport->site;

        return $site !== null && Visibility::mayReportAt($user, $site);
    }

    /**
     * Submitting is editing's last act, so it answers the same question.
     * The precondition that makes submission mean something — a fix, at
     * minimum — is checked by SubmitSiteActivityReportRequest and then by
     * the service, where a field error can be attached to `latitude`.
     */
    public function submit(User $user, SiteActivityReport $siteActivityReport): bool
    {
        return $this->update($user, $siteActivityReport);
    }

    private function owns(User $user, SiteActivityReport $siteActivityReport): bool
    {
        return $user->employee !== null
            && $user->employee->id === $siteActivityReport->employee_id;
    }
}
