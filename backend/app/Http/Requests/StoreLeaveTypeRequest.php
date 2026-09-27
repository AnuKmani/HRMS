<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST|PUT /api/v1/leave-types
 *
 * Leave types are the configuration of what a leave *is* — the entitlement,
 * whether it carries forward, how many days one request may span, whether a
 * doctor's note is compulsory and how long the employee has to produce one.
 * Every number here is read by LeaveDayCalculator / LeaveBalanceService at
 * request time, so this form is the only place those rules are set.
 *
 * Bounds are deliberately generous rather than opinionated: 365 days of
 * entitlement and 365 in one request is not a policy anybody would choose,
 * but refusing it here would mean a deployment with a 6-month sabbatical type
 * had to patch the validation layer to express a legitimate rule.
 *
 * `document_deadline_days` of 0 means "fall back to the organisation-wide
 * setting" — see LeaveType::documentDeadlineDays().
 */
class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveType::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('leaveType')?->id ?? $this->route('leaveType');

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:30', 'alpha_dash',
                Rule::unique('leave_types', 'code')->ignore($id),
            ],
            'description' => ['nullable', 'string', 'max:500'],

            'entitlement_days' => ['required', 'integer', 'min:0', 'max:365'],
            'carry_forward_enabled' => ['sometimes', 'boolean'],
            'carry_forward_limit' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'maximum_days_per_request' => ['required', 'integer', 'min:0', 'max:365'],

            'is_paid' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'document_deadline_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'allow_negative_balance' => ['sometimes', 'boolean'],

            'status' => ['sometimes', Rule::in(LeaveType::STATUSES)],

            // Null means "use the default chain for leave". Pointing a type
            // at a workflow is what makes the approval chain configurable
            // per type without a code change.
            'approval_workflow_id' => [
                'nullable', 'integer',
                Rule::exists('approval_workflows', 'id')->where('subject_type', 'leave'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'entitlement_days.required' => 'Enter the annual entitlement, or 0 if there is none.',
            'maximum_days_per_request.required' => 'Enter the cap for a single request, or 0 for no cap.',
            'document_deadline_days.max' => 'A certificate deadline longer than 90 days is not a deadline.',
        ];
    }
}
