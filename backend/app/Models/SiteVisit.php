<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bounded, deliberate visit to a site — started and ended by the visitor.
 *
 * Not a location track. The table stores two points and two timestamps,
 * nothing between them, and there is no code path that writes a third. That
 * is a product decision as much as a schema one: continuous capture would
 * turn a check-in tool into surveillance, and it is documented as refused in
 * docs/SECURITY.md.
 */
class SiteVisit extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'employee_id',
        'project_id',
        'site_id',
        'started_at',
        'ended_at',
        'start_latitude',
        'start_longitude',
        'start_accuracy',
        'start_distance',
        'end_latitude',
        'end_longitude',
        'end_accuracy',
        'end_distance',
        'purpose',
        'remarks',
        'status',
        'client_event_id',
        'end_client_event_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'start_latitude' => 'decimal:7',
        'start_longitude' => 'decimal:7',
        'start_accuracy' => 'decimal:2',
        'start_distance' => 'decimal:2',
        'end_latitude' => 'decimal:7',
        'end_longitude' => 'decimal:7',
        'end_accuracy' => 'decimal:2',
        'end_distance' => 'decimal:2',
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

    /* ------------------------------------------------------------ queries */

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN)->whereNull('ended_at');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->ended_at === null;
    }

    public function durationMinutes(): ?int
    {
        if ($this->ended_at === null || $this->started_at === null) {
            return null;
        }

        return max(0, (int) $this->started_at->diffInMinutes($this->ended_at));
    }
}
