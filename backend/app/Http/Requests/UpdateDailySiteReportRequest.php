<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\DailySiteReport;

/**
 * PUT /api/v1/daily-site-reports/{dailySiteReport}
 *
 * Same table as creating, relaxed to `sometimes`. Whether *this* document
 * may be edited — yours or one you are scoped to, and still a draft — is
 * DailySiteReportPolicy's answer, asked before any rule here runs.
 *
 * The unique rule survives the trip intact: an update that moves the report
 * to a date another report already holds is refused with the same message
 * as a create that tried to, because the constraint does not care which
 * verb arrived first.
 */
class UpdateDailySiteReportRequest extends StoreDailySiteReportRequest
{
    use MakesRequiredRulesOptional;

    /**
     * The create table, relaxed — except for the rows inside the child
     * arrays.
     *
     * `sometimes` is skipped for a key that is *absent*, and that is exactly
     * what a half-written row is: `manpower: [{count: 4}]` would never be
     * asked for its `category`, because the rule would see no key to check,
     * and DailySiteReportService would be handed a row that names no
     * category to write. A row the payload mentions is a row the payload
     * must complete; a child array the payload omits is still "leave those
     * alone", which is what this class exists to allow.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (parent::rules() as $key => $set) {
            $rules[$key] = str_contains($key, '.')
                ? $set
                : $this->relaxRequired([$key => $set])[$key];
        }

        // `required_without:manpower` is a statement about a *create* —
        // "declare a total if you are sending rows instead". An edit that
        // mentions neither is not a document without a head count; it is a
        // correction to one field, and the row's own derived total stands.
        $rules['total_manpower'] = array_map(
            fn (mixed $rule) => $rule === 'required_without:manpower' ? 'sometimes' : $rule,
            $rules['total_manpower'],
        );

        return $rules;
    }

    public function authorize(): bool
    {
        $report = $this->route('dailySiteReport');

        if (! $report instanceof DailySiteReport) {
            return parent::authorize();
        }

        return $this->user()?->can('update', $report) ?? false;
    }
}
