<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/attendance/check-out
 *
 * Same philosophy as the check-in request, one difference: no selfie. Phase
 * 5 deliberately has no `check_out_selfie_path` column, and a file written
 * with nowhere to record it would be personal data nothing could ever find
 * or delete. If a check-out photograph is wanted later it arrives with its
 * column, not before.
 *
 * `site_id` is required and AttendanceService insists it matches the site
 * the day started at — checking out from somewhere else would otherwise
 * attach the closing coordinates to a place the row never claimed to be.
 */
class StoreCheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkOut', Attendance::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'exists:sites,id'],

            'latitude' => ['required', 'numeric', 'min:-90', 'max:90'],
            'longitude' => ['required', 'numeric', 'min:-180', 'max:180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],

            'client_event_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'in:online,offline'],
            'device_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_out.required' => 'You have not checked in yet, so there is nothing to check out of.',
            'latitude.min' => 'That location is not a valid GPS fix.',
            'latitude.max' => 'That location is not a valid GPS fix.',
            'longitude.min' => 'That location is not a valid GPS fix.',
            'longitude.max' => 'That location is not a valid GPS fix.',
        ];
    }
}
