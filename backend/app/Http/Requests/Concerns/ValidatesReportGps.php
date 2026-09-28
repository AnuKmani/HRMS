<?php

namespace App\Http\Requests\Concerns;

use App\Support\Geo;
use Illuminate\Validation\Validator;

/**
 * The GPS rules shared by every request that carries a site report's fix.
 *
 * Two shapes, one implementation. A **draft** may be saved with no fix at
 * all — a basement, a dead battery, a phone that has not got a satellite
 * yet — so the fields are optional; but if *any* of the three arrives they
 * must all arrive, they must describe a place, and the accuracy must be
 * good enough to mean one. A **submission** is a claim that this report was
 * written where it says it was, so there all three are required.
 *
 * What this deliberately does NOT do is enforce a geofence. Attendance
 * checks in against a site because "were you at work?" is the question it
 * answers; a report is a note *about* a site and its fix is evidence of
 * where it was written, which is a weaker claim and does not deserve the
 * stronger test. The accuracy ceiling is shared with attendance because
 * "±100 m is not a location" is the same statement in both modules — it is
 * read from `hrms.attendance.max_gps_accuracy_metres` rather than given a
 * second config key, so an operator who widens it widens it once.
 *
 * `(0, 0)` is rejected through `Geo::isValidCoordinate()` rather than by the
 * range rules: it is inside both ranges, it is in the Gulf of Guinea, and
 * it is what an uninitialised receiver reports rather than a place anybody
 * stands.
 */
trait ValidatesReportGps
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function reportGpsRules(bool $required): array
    {
        $required = $required ? 'required' : 'nullable';
        $ceiling = (float) config('hrms.attendance.max_gps_accuracy_metres', 100);

        return [
            'latitude' => [$required, 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'numeric', 'between:-180,180'],
            'gps_accuracy' => [$required, 'numeric', 'min:0', 'max:'.$ceiling],
        ];
    }

    protected function checkReportGps(Validator $validator, bool $required): void
    {
        $validator->after(function (Validator $validator) use ($required) {
            $latitude = $this->input('latitude');
            $longitude = $this->input('longitude');
            $accuracy = $this->input('gps_accuracy');

            $missing = $latitude === null || $longitude === null || $accuracy === null;

            if ($required && $missing) {
                $validator->errors()->add(
                    'latitude',
                    'Capture your location before submitting this report.',
                );

                return;
            }

            if (! $missing) {
                $this->checkGpsIsReal($validator, (float) $latitude, (float) $longitude, (float) $accuracy);

                return;
            }

            // Half a fix. Latitude without longitude is not a place, it is a
            // line, and storing it would put a coordinate on a report that
            // cannot be found on a map.
            if ($latitude !== null || $longitude !== null || $accuracy !== null) {
                $validator->errors()->add(
                    'gps_accuracy',
                    'Latitude, longitude and accuracy must be given together.',
                );
            }
        });
    }

    private function checkGpsIsReal(Validator $validator, float $latitude, float $longitude, float $accuracy): void
    {
        if (! Geo::isValidCoordinate($latitude, $longitude)) {
            $validator->errors()->add(
                'latitude',
                'That does not look like a real GPS fix. Capture your location again in the open.',
            );

            return;
        }

        $ceiling = (float) config('hrms.attendance.max_gps_accuracy_metres', 100);

        if ($accuracy > $ceiling) {
            $validator->errors()->add(
                'gps_accuracy',
                sprintf('The location is too imprecise to record (±%s m, limit ±%s m).', $accuracy, $ceiling),
            );
        }
    }
}
