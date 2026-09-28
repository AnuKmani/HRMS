<?php

namespace App\Services\SiteReport;

use App\Models\Site;
use App\Models\SiteActivityReport;
use App\Models\User;
use App\Support\Visibility;

/**
 * The only writer of `site_activity_reports`.
 *
 * Three things happen here that cannot happen anywhere else, and each is
 * here for a reason a controller would get wrong:
 *
 *  - **who wrote it** is read from the session. `employee_id` is set on
 *    create from `$user->employee` and is not a member of any attribute
 *    array built from a payload, so a client that sends it is not refused
 *    for sending it — it simply has nowhere to put it. Two requests from
 *    two phones, one of them lying, produce two honest rows.
 *  - **which site** is resolved, then authorized, then *re*-authorized at
 *    submit. `Visibility::mayReportAt()` is the same three-way test at both
 *    moments, because an assignment that ended between the draft and the
 *    submission is exactly the case a create-time check would miss.
 *  - **state** is written only here: `draft` on create, `submitted` on
 *    submit, never anything else. There is no status field in any payload.
 *
 * `project_id` is taken from the site rather than from the payload. The
 * request already proved they agree (ValidatesReportSite) — this is the
 * second, independent reason they cannot drift: the site is what is
 * physically somewhere, so the site is the source of truth.
 *
 * No transaction is opened. The table has one parent row and its photographs
 * are appended one at a time through a different endpoint, so there is no
 * pair of writes that only make sense together — the daily report, which
 * does have such a pair, is where DB::transaction appears.
 */
class SiteActivityReportService
{
    public function create(User $user, array $data): SiteActivityReport
    {
        $employee = $user->employee;

        if ($employee === null) {
            abort(403, 'This account is not linked to an employee record.');
        }

        $site = $this->resolveSite($data);

        $this->authorizeSite($user, $site);

        $report = new SiteActivityReport;
        $report->fill($this->attributes($data, $site));
        $report->employee_id = $employee->id;
        $report->status = SiteActivityReport::STATUS_DRAFT;
        $report->save();

        return $report;
    }

    public function update(User $user, SiteActivityReport $report, array $data): SiteActivityReport
    {
        $this->assertEditable($report);

        // Re-resolved rather than trusted from the row: the payload may be
        // moving the report to another site, and if it says nothing about
        // the site then the row's own site is what still has to be theirs.
        $site = $this->resolveSite($data, $report->site_id);

        $this->authorizeSite($user, $site);

        $report->fill($this->attributes($data, $site));
        $report->save();

        return $report;
    }

    /**
     * draft -> submitted, and the fix becomes mandatory.
     *
     * The GPS rules have already run (SubmitSiteActivityReportRequest), so
     * by here all three values exist, describe a real place and are within
     * the accuracy ceiling. What is *not* checked here and never will be:
     * whether the phone was inside the site's geofence. Attendance answers
     * "were you at work?"; this answers "where was this written?", which is
     * a weaker claim with a weaker test.
     */
    public function submit(User $user, SiteActivityReport $report, array $data): SiteActivityReport
    {
        $this->assertEditable($report);

        $site = $report->site;

        if ($site !== null) {
            $this->authorizeSite($user, $site);
        }

        $report->fill([
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'gps_accuracy' => $data['gps_accuracy'],
            'status' => SiteActivityReport::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $report->save();

        return $report;
    }

    /**
     * The attributes a payload may address, and nothing else.
     *
     * A deliberately enumerated list rather than `$data` passed through:
     * `employee_id`, `status` and `submitted_at` are the three columns this
     * module owns outright, and building the array by hand is what makes
     * "the client cannot set them" a property of *this* line rather than of
     * whatever happens to be in `$fillable` six months from now.
     *
     * Only keys the payload actually carried are included. On an update a
     * partial body means "leave the rest alone", and defaulting the
     * absent ones to null here would quietly erase a work description the
     * next time somebody corrected a typo in `remarks`. On a create the
     * required keys are always present and the rest fall back to the
     * column defaults, which are NULL anyway.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Site $site): array
    {
        $attributes = [
            // From the site, always — see the class docblock.
            'project_id' => $site->project_id,
            'site_id' => $site->id,
        ];

        foreach ([
            'report_date',
            'work_category',
            'work_performed',
            'progress_percentage',
            'manpower',
            'materials_used',
            'equipment_used',
            'issues',
            'safety_issues',
            'remarks',
            'latitude',
            'longitude',
            'gps_accuracy',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        if (array_key_exists('progress_percentage', $attributes)) {
            $attributes['progress_percentage'] = (int) $attributes['progress_percentage'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSite(array $data, ?int $fallback = null): Site
    {
        $siteId = $data['site_id'] ?? $fallback;

        $site = $siteId === null ? null : Site::query()->find($siteId);

        if ($site === null) {
            abort(422, 'That site no longer exists.');
        }

        return $site;
    }

    private function authorizeSite(User $user, Site $site): void
    {
        if (! Visibility::mayReportAt($user, $site)) {
            abort(403, 'You can only file a report for a site you are assigned to.');
        }
    }

    /**
     * 409, not 403: the *person* is authorized, the *state* is not. Saying
     * "you may not" to somebody who plainly may is how a support ticket
     * starts.
     */
    private function assertEditable(SiteActivityReport $report): void
    {
        if (! $report->isEditable()) {
            abort(409, 'Only a draft can be edited. This report has already been submitted.');
        }
    }
}
