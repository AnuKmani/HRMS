<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportSite;
use App\Models\DailySiteReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/daily-site-reports
 *
 * The official site-day document: one per site per date, and the unique rule
 * below is the business rule the migration's index enforces. It is expressed
 * as *validation* rather than left to the driver so a supervisor who picks a
 * date somebody has already written gets a field error naming the date —
 * not a 500 with a constraint name in it.
 *
 * `created_by` is absent for the same reason `employee_id` is absent from
 * the activity request: the session is the author, and there is no payload
 * that can claim otherwise. There is no `total_manpower` requirement if
 * `manpower` rows arrive — DailySiteReportService sums the rows and writes
 * that, because a client that can state a total the rows do not add up to
 * has handed the PDF a number nobody can reproduce.
 *
 * `status`, `submitted_at` and `approved_at` are all absent. This phase
 * offers draft and submit, and submit is its own endpoint with its own
 * precondition.
 *
 * An Employee reaching this request will be refused by `authorize()` before
 * any rule runs: `daily_site_reports.create` is not granted to that role.
 */
class StoreDailySiteReportRequest extends FormRequest
{
    use ValidatesReportSite;

    public function authorize(): bool
    {
        return $this->user()?->can('create', DailySiteReport::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            'report_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', $this->uniqueSiteDate()],

            'total_manpower' => ['required_without:manpower', 'integer', 'min:0', 'max:1000000'],

            // One row per category, category free text — see the
            // daily_site_report_manpower migration for why the vocabulary is
            // deliberately not a lookup table.
            'manpower' => ['nullable', 'array', 'max:30'],
            'manpower.*.category' => ['required', 'string', 'max:60'],
            'manpower.*.count' => ['required', 'integer', 'min:0', 'max:1000000'],

            'work_planned' => ['required', 'string', 'max:4000'],
            'work_completed' => ['required', 'string', 'max:4000'],

            'materials' => ['nullable', 'array', 'max:50'],
            'materials.*.material_name' => ['required', 'string', 'max:150'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'materials.*.unit' => ['required', 'string', 'max:30'],
            'materials.*.remarks' => ['nullable', 'string', 'max:500'],

            'equipment' => ['nullable', 'array', 'max:50'],
            'equipment.*.equipment_name' => ['required', 'string', 'max:150'],
            'equipment.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'equipment.*.operating_hours' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'equipment.*.condition' => ['nullable', 'string', 'max:30'],
            'equipment.*.remarks' => ['nullable', 'string', 'max:500'],

            'safety_observations' => ['nullable', 'string', 'max:1000'],
            'delays' => ['nullable', 'string', 'max:1000'],
            'issues' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkProjectMatchesSite($validator);
    }

    /**
     * "One official report per site per date", scoped to the site named in
     * this payload and ignoring the row being edited on an update — the
     * route parameter is only present for PUT, so the same expression
     * serves both.
     */
    private function uniqueSiteDate(): Unique
    {
        $rule = Rule::unique('daily_site_reports', 'report_date');

        $report = $this->route('dailySiteReport');

        if ($report instanceof DailySiteReport) {
            $rule = $rule->ignore($report);
        }

        $siteId = $this->input('site_id');

        // Absent or malformed `site_id` is `exists:sites,id`'s answer, and
        // scoping the uniqueness check to nothing would let it pass for the
        // right reason anyway — no two rows share a null site.
        if (is_scalar($siteId) && $siteId !== '') {
            $rule = $rule->where('site_id', (int) $siteId);
        }

        return $rule;
    }
}
