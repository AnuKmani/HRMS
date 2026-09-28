<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportGps;
use App\Http\Requests\Concerns\ValidatesReportSite;
use App\Models\SiteActivityReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/site-activity-reports
 *
 * What a person may claim about their own day at a site — and, pointedly,
 * nothing about *who* they are: `employee_id` does not appear here, does
 * not appear in SiteActivityReportService's attribute list, and is not a
 * column a client can address. The bearer token says who is writing, and a
 * payload carrying `employee_id` has nowhere to put it.
 *
 * `project_id` and `site_id` are both required and are checked against each
 * other (see ValidatesReportSite), because a report filed under a project
 * the site does not belong to is a report that will be read by the wrong
 * people in the wrong review.
 *
 * GPS is optional here and required at submit — see ValidatesReportGps for
 * why a draft may be written in a basement and a submission may not.
 *
 * No status, no `submitted_at`: status changes only through
 * POST .../{report}/submit, which is a different question with a different
 * precondition (the fix, the photographs, the words).
 */
class StoreSiteActivityReportRequest extends FormRequest
{
    use ValidatesReportGps;
    use ValidatesReportSite;

    public function authorize(): bool
    {
        return $this->user()?->can('create', SiteActivityReport::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            'report_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],

            'work_category' => ['required', 'string', 'max:60'],
            'work_performed' => ['required', 'string', 'max:2000'],
            'progress_percentage' => ['required', 'integer', 'between:0,100'],

            // Free text, and deliberately so — see the migration for why
            // this module does not carry the child rows the daily report
            // does. Optional because a quiet day on one of the three is
            // information, not an omission.
            'manpower' => ['nullable', 'string', 'max:2000'],
            'materials_used' => ['nullable', 'string', 'max:2000'],
            'equipment_used' => ['nullable', 'string', 'max:2000'],

            'issues' => ['nullable', 'string', 'max:1000'],
            'safety_issues' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], $this->reportGpsRules(false));
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkProjectMatchesSite($validator);
        $this->checkReportGps($validator, false);
    }
}
