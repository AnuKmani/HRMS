<?php

namespace App\Http\Requests;

use App\Models\OvertimeRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST|PUT /api/v1/overtime
 *
 * What a client may claim about extra hours, and nothing more.
 *
 * Absent, on purpose: `approved_minutes` (the grant belongs to the approver,
 * not to the person claiming), `payroll_eligible` (set once, when a chain
 * completes), `status`, every `*_at`, `employee_id`, `attendance_id`,
 * `current_approval_step` and `approval_workflow_id`. The attendance link is
 * resolved from the date inside OvertimeService so a claim can never be
 * attached to a day that belongs to somebody else.
 *
 * `before_or_equal:today` is the one date rule that looks strict next to
 * leave, where future dates are the norm. The difference is what the two are
 * describing: leave is a plan that has not happened, overtime is time already
 * worked — and a request for tomorrow's overtime is a claim about hours
 * nobody has spent yet.
 *
 * The 1440-minute ceiling is a sanity bound, not a policy: no single day has
 * more than 24 hours in it, and anything that wants more than that across
 * several days is several requests.
 */
class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OvertimeRequest::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'overtime_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'requested_minutes' => ['required', 'integer', 'min:1', 'max:1440'],

            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],

            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'overtime_date.required' => 'Enter the date the overtime was worked.',
            'overtime_date.before_or_equal' => 'Overtime can only be claimed for a day that has already happened.',
            'requested_minutes.min' => 'Enter how many extra minutes were worked.',
            'requested_minutes.max' => 'A single day cannot hold more than 1440 minutes. Split a longer claim across days.',
            'reason.required' => 'Say why the extra time was needed.',
        ];
    }
}
