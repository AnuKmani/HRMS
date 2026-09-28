<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's account of one site-day.
 *
 * The row belongs to whoever was holding the session when it was created —
 * `employee_id` is set by SiteActivityReportService from the authenticated
 * user and is not present in any payload, so "whose report is this?" is a
 * question the server answered rather than one a client asserted. The
 * policy asks the same column again, which is why the two cannot drift.
 *
 * `status` walks `draft -> submitted` and stops. Submission is a claim
 * that the fix, the photographs and the words were all present, so an
 * already-submitted report is frozen: editing it would silently withdraw
 * evidence from a record somebody may already have read. Un-submitting
 * would be a worse lie, so there is no endpoint for it either.
 *
 * The latitude/longitude/accuracy triple is the same triple attendance
 * records carry, stored the same way, and required at *submit* rather than
 * at create — a draft written in a basement with no fix is a normal thing
 * to have; a submitted report with no fix is not.
 */
class SiteActivityReport extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
    ];

    protected $fillable = [
        'employee_id',
        'project_id',
        'site_id',
        'report_date',
        'work_category',
        'work_performed',
        'progress_percentage',
        'manpower',
        'materials_used',
        'equipment_used',
        'issues',
        'safety_issues',
        'remarks',
        'latitude',
        'longitude',
        'gps_accuracy',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'progress_percentage' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'gps_accuracy' => 'decimal:2',
        'submitted_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SiteActivityReportPhoto::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /* ------------------------------------------------------------ helpers */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Only a draft may still be changed or have photographs added to it.
     */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /**
     * A submitted report carries its own proof of where it was written.
     *
     * Nullable here rather than a not-null column so a *draft* can exist
     * without one; SiteActivityReportService is what turns "all three
     * present" into a precondition of `submit`.
     */
    public function hasGpsFix(): bool
    {
        return $this->latitude !== null
            && $this->longitude !== null
            && $this->gps_accuracy !== null;
    }
}
