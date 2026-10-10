<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site visit a Project Manager planned for a field engineer.
 *
 * Stored statuses are planned / completed / cancelled. "Missed" is not
 * stored: it is a planned visit whose day has passed, worked out on read
 * so it can never disagree with the calendar.
 */
class SiteVisitTask extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Derived, never stored. */
    public const STATUS_MISSED = 'missed';

    /** How far ahead a visit may be planned. */
    public const MAX_DAYS_AHEAD = 31;

    protected $fillable = [
        'project_id',
        'site_id',
        'employee_id',
        'assigned_by',
        'visit_date',
        'purpose',
        'notes',
        'status',
        'completed_at',
        'site_visit_id',
        'completion_note',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function siteVisit(): BelongsTo
    {
        return $this->belongsTo(SiteVisit::class);
    }

    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_PLANNED
            && $this->visit_date !== null
            && $this->visit_date->lt(now()->startOfDay())) {
            return self::STATUS_MISSED;
        }

        return $this->status;
    }
}
