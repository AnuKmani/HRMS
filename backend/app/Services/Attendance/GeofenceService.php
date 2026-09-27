<?php

namespace App\Services\Attendance;

use App\Models\Site;
use App\Services\SettingsService;
use App\Support\Geo;

/**
 * Is this device standing at this site? The server's answer, and the only
 * one that counts.
 *
 * Three checks happen in a fixed order, and the order is the point:
 *
 *   1. Could these coordinates exist? (range, finiteness, not (0,0))
 *   2. Does the receiver know where it is well enough to be asked? (accuracy)
 *   3. Does the site even have a fence to stand in? (coordinates + radius)
 *   4. Is the point inside it? (haversine distance vs radius)
 *
 * Asking question 4 first would let a 400-metre-accuracy fix decide whether
 * somebody is on site, and answering question 3 after 4 would report "you are
 * 800 m away" for a site nobody has geolocated yet — a distance computed
 * against nothing.
 *
 * Every threshold comes from config/hrms.php or the settings table. There is
 * no metre, degree or accuracy figure in this file that an operator cannot
 * change without a deploy.
 */
final class GeofenceService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Judge a reported position against a site's geofence.
     *
     * The returned distance is server-computed. Flutter may draw its own
     * estimate for feedback, but a client-supplied distance is never read —
     * see docs/SECURITY.md.
     */
    public function evaluate(
        Site $site,
        float $latitude,
        float $longitude,
        ?float $accuracyMetres = null,
    ): GeofenceResult {
        $maxAccuracy = (float) config('hrms.attendance.max_gps_accuracy_metres', 100);

        if (! Geo::isValidCoordinate($latitude, $longitude)) {
            return GeofenceResult::reject(
                GeofenceResult::CODE_INVALID_COORDINATES,
                'That location is not a valid GPS fix. Turn on location services, move into open sky and try again.',
                accuracyMetres: $accuracyMetres,
                hasSiteCoordinates: $this->hasSiteCoordinates($site),
            );
        }

        if ($accuracyMetres !== null && $accuracyMetres > 0) {
            if ($accuracyMetres > $maxAccuracy) {
                return GeofenceResult::reject(
                    GeofenceResult::CODE_POOR_ACCURACY,
                    sprintf(
                        'Your GPS accuracy is %.0f m and this site needs better than %.0f m. Move into open sky, away from the building, and try again.',
                        $accuracyMetres,
                        $maxAccuracy,
                    ),
                    accuracyMetres: $accuracyMetres,
                    hasSiteCoordinates: $this->hasSiteCoordinates($site),
                );
            }
        }

        if (! $this->hasSiteCoordinates($site)) {
            return GeofenceResult::reject(
                GeofenceResult::CODE_SITE_NOT_CONFIGURED,
                'This site has no location set, so check-in is not possible here. Ask your administrator to add the site coordinates.',
                hasSiteCoordinates: false,
                accuracyMetres: $accuracyMetres,
            );
        }

        $radius = $site->effectiveGeofenceRadius(
            (float) $this->settings->int('attendance.default_geofence_radius_m', 100),
        );

        if ($radius <= 0) {
            return GeofenceResult::reject(
                GeofenceResult::CODE_SITE_NOT_CONFIGURED,
                'This site has no geofence radius set, so check-in is not possible here. Ask your administrator to configure it.',
                radiusMetres: 0,
                accuracyMetres: $accuracyMetres,
            );
        }

        $distance = Geo::distanceMetres(
            (float) $site->latitude,
            (float) $site->longitude,
            $latitude,
            $longitude,
        );

        if ($distance > $radius) {
            return GeofenceResult::reject(
                GeofenceResult::CODE_OUTSIDE,
                sprintf(
                    'You are about %d m from %s and the allowed area is %d m. Move closer to the site and try again.',
                    (int) round($distance),
                    $site->name,
                    (int) round($radius),
                ),
                radiusMetres: $radius,
                accuracyMetres: $accuracyMetres,
                distanceMetres: $distance,
            );
        }

        return GeofenceResult::ok($distance, $radius, $accuracyMetres);
    }

    private function hasSiteCoordinates(Site $site): bool
    {
        return $site->latitude !== null && $site->longitude !== null;
    }
}
