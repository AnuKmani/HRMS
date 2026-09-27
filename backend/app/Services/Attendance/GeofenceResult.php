<?php

namespace App\Services\Attendance;

/**
 * The verdict of one geofence evaluation, plus the numbers behind it.
 *
 * Returned rather than thrown so callers can both decide (allowed?) and
 * report (how far? from what radius?) from one object — a service that
 * throws on rejection loses the distance the client needs to draw "you are
 * 40 m outside" on screen.
 */
final class GeofenceResult
{
    public const CODE_OK = 'ok';

    public const CODE_INVALID_COORDINATES = 'invalid_coordinates';

    public const CODE_POOR_ACCURACY = 'poor_accuracy';

    public const CODE_SITE_NOT_CONFIGURED = 'site_not_configured';

    public const CODE_OUTSIDE = 'outside_geofence';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?float $distanceMetres,
        public readonly ?float $radiusMetres,
        public readonly ?float $accuracyMetres,
        public readonly bool $hasSiteCoordinates,
    ) {}

    public static function ok(?float $distanceMetres, float $radiusMetres, ?float $accuracyMetres): self
    {
        return new self(
            allowed: true,
            code: self::CODE_OK,
            message: null,
            distanceMetres: $distanceMetres,
            radiusMetres: $radiusMetres,
            accuracyMetres: $accuracyMetres,
            hasSiteCoordinates: true,
        );
    }

    public static function reject(
        string $code,
        string $message,
        ?float $radiusMetres = null,
        ?float $accuracyMetres = null,
        bool $hasSiteCoordinates = true,
        ?float $distanceMetres = null,
    ): self {
        return new self(
            allowed: false,
            code: $code,
            message: $message,
            distanceMetres: $distanceMetres,
            radiusMetres: $radiusMetres,
            accuracyMetres: $accuracyMetres,
            hasSiteCoordinates: $hasSiteCoordinates,
        );
    }

    /**
     * The distance Flutter is allowed to redraw on screen — advisory only;
     * this server-side number is the one that decided.
     */
    public function distanceMetresRounded(): ?int
    {
        return $this->distanceMetres === null ? null : (int) round($this->distanceMetres);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'allowed' => $this->allowed,
            'code' => $this->code,
            'distance_metres' => $this->distanceMetresRounded(),
            'radius_metres' => $this->radiusMetres === null ? null : (int) round($this->radiusMetres),
            'accuracy_metres' => $this->accuracyMetres === null ? null : (float) $this->accuracyMetres,
        ];
    }
}
