<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\SiteActivityReport;

/**
 * PUT /api/v1/site-activity-reports/{siteActivityReport}
 *
 * Same table as creating, relaxed to `sometimes`. Whether *this* report may
 * be edited at all — yours, and still a draft — is SiteActivityReportPolicy's
 * answer, asked before any rule here runs.
 *
 * `employee_id` is still absent, and for a second reason on update: an edit
 * is exactly when a client might imagine it could hand a report to a
 * different person.
 */
class UpdateSiteActivityReportRequest extends StoreSiteActivityReportRequest
{
    use MakesRequiredRulesOptional;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->relaxRequired(parent::rules());
    }

    public function authorize(): bool
    {
        $report = $this->route('siteActivityReport');

        if (! $report instanceof SiteActivityReport) {
            return parent::authorize();
        }

        return $this->user()?->can('update', $report) ?? false;
    }
}
