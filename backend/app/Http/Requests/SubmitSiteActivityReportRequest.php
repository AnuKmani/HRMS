<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportGps;
use App\Models\SiteActivityReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/site-activity-reports/{siteActivityReport}/submit
 *
 * The one transition this module has: draft -> submitted, and never back.
 * Submitting is a claim that the report is *finished*, so the fix that was
 * optional on the draft becomes mandatory here — a submitted report with no
 * coordinates is a note about a place that cannot be found.
 *
 * No other field is accepted. Status, `submitted_at` and every other
 * state-bearing column are written by SiteActivityReportService, and
 * `remarks` is absent on purpose: adding words at the moment of submission
 * would be editing a record the policy has just decided is complete.
 */
class SubmitSiteActivityReportRequest extends FormRequest
{
    use ValidatesReportGps;

    public function authorize(): bool
    {
        $report = $this->route('siteActivityReport');

        if (! $report instanceof SiteActivityReport) {
            return false;
        }

        return $this->user()?->can('submit', $report) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->reportGpsRules(true);
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkReportGps($validator, true);
    }
}
