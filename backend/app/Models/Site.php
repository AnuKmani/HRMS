<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'project_id',
        'name',
        'code',
        'address',
        'latitude',
        'longitude',
        'geofence_radius',
        'site_manager_id',
        'site_supervisor_id',
        'working_hours_setting_id',
        'shift_id',
        'status',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geofence_radius' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function siteManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'site_manager_id');
    }

    public function siteSupervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'site_supervisor_id');
    }

    /**
     * Optional per-site override of the default working-hours setting.
     */
    public function workingHoursSetting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'working_hours_setting_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeSiteAssignment::class);
    }

    /**
     * Does the point fall inside this site's geofence?
     *
     * Radius comes from the row (or the configured default) — never a
     * hard-coded constant.
     */
    public function withinGeofence(float $lat, float $lng, ?float $fallbackRadius = null): bool
    {
        if ($this->latitude === null || $this->longitude === null) {
            return false;
        }

        $radius = (float) ($this->geofence_radius ?? $fallbackRadius ?? 0);

        if ($radius <= 0) {
            return false;
        }

        return $this->haversineMetres(
            (float) $this->latitude,
            (float) $this->longitude,
            $lat,
            $lng,
        ) <= $radius;
    }

    /**
     * Great-circle distance in metres (WGS-84 mean earth radius).
     */
    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;   // mean earth radius, metres

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
