<?php

namespace App\Http\Requests\Concerns;

use App\Models\Site;
use Illuminate\Validation\Validator;

/**
 * "Is that project the project this site belongs to?"
 *
 * Both site reports carry a project *and* a site, and the pair is the one
 * relationship in the payload that a client can get wrong without any single
 * field being invalid: `project_id` may name a real project and `site_id` a
 * real site, and the two may have nothing to do with each other. Left
 * unchecked the row would store happily and then disagree with
 * `employee_site_assignments`, which insists the same two columns match —
 * so the error would surface later, somewhere else, against a different row.
 *
 * The check runs against the *site's* own `project_id`, not the reverse:
 * the site is the thing that is physically somewhere, so the site wins and
 * the project is the one that has to be corrected. Service-side, the stored
 * `project_id` is taken from the site anyway — this exists so the person
 * filling the form is told *here* rather than finding a report filed under
 * the wrong project next month.
 *
 * Asked only when `project_id` is actually present: an update that moves a
 * report's site and says nothing about the project should be corrected from
 * the site, not refused for having been silent.
 */
trait ValidatesReportSite
{
    protected function checkProjectMatchesSite(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $siteId = $this->input('site_id');

            if ($siteId === null || $siteId === '') {
                return;
            }

            /** @var Site|null $site */
            $site = Site::find($siteId);

            // `exists:sites,id` owns the "no such site" answer, and it names
            // the field. Duplicating it here would only produce a second,
            // worse-worded message for the same input.
            if ($site === null) {
                return;
            }

            $projectId = $this->input('project_id');

            if ($projectId !== null && (int) $projectId !== $site->project_id) {
                $validator->errors()->add(
                    'project_id',
                    sprintf('That project is not the project "%s" belongs to.', $site->name),
                );
            }
        });
    }
}
