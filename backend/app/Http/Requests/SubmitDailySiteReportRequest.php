<?php

namespace App\Http\Requests;

use App\Models\DailySiteReport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/daily-site-reports/{dailySiteReport}/submit
 *
 * The one transition this phase offers on the official document:
 * draft -> submitted, never back. It takes no body — there is nothing to
 * say at submission that could not have been said while editing — so the
 * rules are empty and every question is asked of the policy (may you, on
 * this document, right now?) and of DailySiteReportService (is it in a
 * state where submitting exists, and does it have both of its narratives?).
 *
 * Deliberately no `approve` sibling: `approved_at` is reserved for a later
 * phase, and shipping an endpoint that writes it without a workflow behind
 * it would make the column a button anybody with `update` could press.
 */
class SubmitDailySiteReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('dailySiteReport');

        if (! $report instanceof DailySiteReport) {
            return false;
        }

        return $this->user()?->can('submit', $report) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
