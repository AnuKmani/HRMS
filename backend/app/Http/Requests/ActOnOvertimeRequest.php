<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/overtime/{overtimeRequest}/{submit|approve|reject|cancel}
 *
 * The overtime twin of ActOnLeaveRequest, for the same reasons: all four
 * transitions take a remark, and none of them takes anything that could
 * change what the request *is*.
 *
 * `approved_minutes` is the one field overtime has that leave does not, and
 * it is accepted here rather than on the resource because it is not a property
 * of the request — it is what *this* approver is granting at *this* step. It
 * is ignored unless the transition is approve, and OvertimeService refuses a
 * number above what was claimed.
 */
class ActOnOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Deliberately true: every ability here needs the instance, and the
        // controller asks OvertimeRequestPolicy for it with the model already in
        // hand. See ActOnLeaveRequest.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:500'],
            'approved_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approved_minutes.min' => 'Grant at least one minute, or reject the request.',
            'approved_minutes.max' => 'A single day cannot hold more than 1440 minutes.',
        ];
    }
}
