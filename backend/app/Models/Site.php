<?php

namespace App\Models;

use App\Support\Geo;
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
     * The radius actually in force for this site.
     *
     * The row wins; `attendance.default_geofence_radius_m` (a seeded setting)
     * is the fallback for a site whose radius has not been filled in yet. No
     * literal appears here — the number lives in the settings table so an
     * operator can change it without a deploy.
     */
    public function effectiveGeofenceRadius(float $configuredDefault): float
    {
        return (float) ($this->geofence_radius ?? $configuredDefault);
    }

    /**
     * Does the point fall inside this site's geofence?
     *
     * Radius comes from the row (or the configured default) — never a
     * hard-coded constant. The distance itself is Geo's, so this and the
     * attendance geofence service can never answer differently.
     */
    public function withinGeofence(float $lat, float $lng, ?float $fallbackRadius = null): bool
    {
        if ($this->latitude === null || $this->longitude === null) {
            return false;
        }

        $radius = $this->effectiveGeofenceRadius((float) ($fallbackRadius ?? 0));

        if ($radius <= 0) {
            return false;
        }

        return Geo::distanceMetres(
            (float) $this->latitude,
            (float) $this->longitude,
            $lat,
            $lng,
        ) <= $radius;
    }
}
