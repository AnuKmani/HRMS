<?php

namespace App\Http\Requests\Concerns;

use App\Models\Site;
use Illuminate\Validation\Validator;

/**
 * Coordinate + geofence rules shared by the site create and update requests.
 *
 * The rule that matters is not the range check — Laravel does that — but the
 * *consistency* check. Latitude alone is a line, not a place: a half-entered
 * geofence would store happily and then reject every check-in at that site
 * for a reason nobody could see in the row. So either all three of latitude,
 * longitude and radius are present, or none is, and a site with no geofence
 * at all is a legitimate thing to save (validation of check-in against it is
 * Phase 5's problem, and it fails closed).
 *
 * On update the *merged* result is checked, not the payload: clearing one
 * coordinate while the other two remain on the row is exactly the mistake
 * this exists to catch.
 */
trait ValidatesSiteCoordinates
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function coordinateRules(): array
    {
        $min = (float) config('hrms.geofence.min_radius_metres');
        $max = (float) config('hrms.geofence.max_radius_metres');

        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius' => ['nullable', 'numeric', "min:{$min}", "max:{$max}"],
        ];
    }

    protected function checkCoordinateConsistency(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Site|null $site */
            $site = $this->route('site');

            $values = [
                'latitude' => $this->input('latitude', $site?->latitude),
                'longitude' => $this->input('longitude', $site?->longitude),
                'geofence_radius' => $this->input('geofence_radius', $site?->geofence_radius),
            ];

            $present = array_filter(
                $values,
                fn ($value) => $value !== null && $value !== '',
            );

            if (count($present) === 0 || count($present) === 3) {
                return;
            }

            foreach (array_keys($values) as $field) {
                if (! isset($present[$field])) {
                    $validator->errors()->add(
                        $field,
                        'Latitude, longitude and geofence radius must be provided together.',
                    );
                }
            }
        });
    }
}
