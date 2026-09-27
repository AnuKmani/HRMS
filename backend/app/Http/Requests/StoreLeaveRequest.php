<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST|PUT /api/v1/leave
 *
 * What a client may say about a leave request, and nothing more.
 *
 * Notably absent: `requested_days`. The number of days is not a claim a
 * client gets to make — LeaveDayCalculator derives it from the date range and
 * the holiday calendar, and accepting it here would let a request be recorded
 * as 1 day for 5. Same for `status`, every `*_at`, `current_approval_step`,
 * the LOP columns and the certificate columns: those belong to the service
 * and to nobody else.
 *
 * `leave_type_id` is checked for existence only. Whether the type is *active*
 * is a business rule with a message LeaveRequestService can write properly,
 * and an `exists:` rule cannot tell an archived type from a missing one.
 */
class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveRequest::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],

            // `date_format` rather than `date`: "2026-02-30" passes Laravel's
            // `date` rule, and a range built on it would be silently shifted
            // by whatever normalised it next.
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],

            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'leave_type_id.required' => 'Choose a leave type.',
            'leave_type_id.exists' => 'That leave type does not exist.',
            'end_date.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
