<?php

namespace App\Http\Requests;

use App\Models\SiteVisit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/site-visits/start
 *
 * A visit is started the same way a day is: by a linked employee standing
 * somewhere they are posted. `purpose` is the only narrative field, and it
 * is required because "why were you there" is the entire reason the record
 * exists — a start with no purpose is a duration nobody can explain.
 *
 * No `employee_id`, no `project_id`, no `started_at`: the token supplies the
 * person, the site row supplies the project, and the server clock supplies
 * the moment. Nothing between the two points is asked for, because nothing
 * between them is ever recorded.
 */
class StartSiteVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('start', SiteVisit::class) ?? false;
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

            'purpose' => ['required', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:500'],

            'client_event_id' => ['nullable', 'uuid'],
            'device_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'purpose.required' => 'Say what you are visiting the site for.',
            'latitude.min' => 'That location is not a valid GPS fix.',
            'latitude.max' => 'That location is not a valid GPS fix.',
            'longitude.min' => 'That location is not a valid GPS fix.',
            'longitude.max' => 'That location is not a valid GPS fix.',
        ];
    }
}
