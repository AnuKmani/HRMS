<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/attendance/check-in
 *
 * What this accepts is a *claim about the world*: where the phone is, how
 * well it knows, a photograph, and a key that makes a retry safe. What it
 * pointedly does NOT accept is anything about the day itself — no
 * `employee_id`, no `project_id`, no `attendance_date`, no `late_minutes`,
 * no `working_minutes`, no distance. Those are not "ignored fields", they
 * are fields that simply do not exist here, so a payload carrying them has
 * nowhere to put them. AttendanceService derives every one of them from the
 * token, the site row and the clock.
 *
 * `selfie` is required and validated three ways — declared type, sniffed
 * MIME and size — because a check-in with no evidence attached is not a
 * check-in, and because the one place a user can be told "that is not an
 * image" before anything is written is here.
 *
 * Coordinate range is checked here so a nonsense value gets a field error
 * rather than a service call; everything subtler (accuracy quality, (0,0),
 * is the device actually at the site) is GeofenceService's, where it can
 * produce a message that says what to do about it.
 */
class StoreCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkIn', Attendance::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) config('hrms.storage.selfie_max_kilobytes', 5120);

        return [
            'site_id' => ['required', 'integer', 'exists:sites,id'],

            'latitude' => ['required', 'numeric', 'min:-90', 'max:90'],
            'longitude' => ['required', 'numeric', 'min:-180', 'max:180'],

            // Upper bound only. The threshold that actually matters — the
            // configured maximum accuracy worth acting on — is applied by
            // GeofenceService so the message can name both numbers.
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],

            'selfie' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$maxKilobytes,
            ],

            'client_event_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'in:online,offline'],
            'device_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'selfie.required' => 'A selfie is required to check in.',
            'selfie.mimes' => 'That file is not a JPG, PNG or WebP image.',
            'selfie.mimetypes' => 'That file is not a JPG, PNG or WebP image.',
            'selfie.max' => 'That selfie is too large. Retake it — the app compresses before sending.',
            'latitude.min' => 'That location is not a valid GPS fix.',
            'latitude.max' => 'That location is not a valid GPS fix.',
            'longitude.min' => 'That location is not a valid GPS fix.',
            'longitude.max' => 'That location is not a valid GPS fix.',
        ];
    }
}
