<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/site-visits/{siteVisit}/end
 *
 * The closing coordinates matter for the same reason the opening ones do:
 * a visit that claims to have run from 11:20 to 12:05 but recorded no
 * endpoint is an assertion, not evidence. Required, not optional.
 *
 * `site_id` is absent on purpose — you end the visit you started, at the
 * site that visit already names. Accepting a site here would only create a
 * second opinion about where the first one happened.
 */
class EndSiteVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visit = $this->route('siteVisit');

        return $this->user()?->can('end', $visit) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'min:-90', 'max:90'],
            'longitude' => ['required', 'numeric', 'min:-180', 'max:180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],

            'remarks' => ['nullable', 'string', 'max:500'],

            'client_event_id' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.min' => 'That location is not a valid GPS fix.',
            'latitude.max' => 'That location is not a valid GPS fix.',
            'longitude.min' => 'That location is not a valid GPS fix.',
            'longitude.max' => 'That location is not a valid GPS fix.',
        ];
    }
}
