<?php

namespace App\Support;

/**
 * Great-circle geometry, in one place.
 *
 * `Site::withinGeofence()` and `GeofenceService` both need the same distance,
 * and two copies of a trigonometric formula are two chances for them to
 * disagree about where the edge of a site is. Both ask this class.
 *
 * The haversine formula is used rather than a naive Pythagorean approximation
 * on lat/lng: the latter treats a degree of longitude as equal to a degree of
 * latitude, which is 85% wrong at 30° and completely wrong near the poles. At
 * the scale a site geofence operates (10 m – 10 km) haversine agrees with the
 * Vincenty inverse solution to well under a metre, and it has no iteration to
 * get wrong.
 */
final class Geo
{
    /** WGS-84 mean earth radius, metres. */
    public const EARTH_RADIUS_METRES = 6371000.0;

    private function __construct() {}

    /**
     * Distance between two WGS-84 points, in metres.
     */
    public static function distanceMetres(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        // 2 * atan2(sqrt(a), sqrt(1 - a)) is numerically stable across the
        // whole sphere, where asin(sqrt(a)) loses precision as a approaches 1.
        return self::EARTH_RADIUS_METRES * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Is this a coordinate a GPS receiver could plausibly have produced?
     *
     * Deliberately stricter than "-90..90": `(0, 0)` is in the Gulf of Guinea
     * and is the signature of an uninitialised fix rather than a location,
     * so it is rejected outright instead of quietly failing the geofence.
     */
    public static function isValidCoordinate(?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        if (! is_finite($latitude) || ! is_finite($longitude)) {
            return false;
        }

        if ($latitude < -90.0 || $latitude > 90.0) {
            return false;
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            return false;
        }

        return ! ($latitude === 0.0 && $longitude === 0.0);
    }
}
