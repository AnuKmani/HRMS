<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's one working day.
 *
 * The row is written exactly twice: once at check-in (everything about the
 * morning) and once at check-out (everything about the rest of the day).
 * AttendanceService is the only code that performs either write, and
 * AttendanceStatusCalculator is the only code that chooses [STATUS_*] —
 * so a status rule changed in one place changes everywhere.
 *
 * Nothing on this row is client-supplied except the coordinates, the
 * accuracy and the selfie. Employee, project, date, shift, schedule,
 * distance, lateness, working time and status are all derived server-side
 * from the authenticated session (see docs/SECURITY.md).
 */
class Attendance extends Model
{
    use HasFactory;

    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_MISSING_CHECKOUT = 'missing_checkout';

    public const STATUS_MANUALLY_ADJUSTED = 'manually_adjusted';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LATE,
        self::STATUS_INCOMPLETE,
        self::STATUS_MISSING_CHECKOUT,
        self::STATUS_MANUALLY_ADJUSTED,
    ];

    /** Where the record came from — orthogonal to its status. */
    public const SOURCE_ONLINE = 'online';

    public const SOURCE_OFFLINE = 'offline';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_ONLINE,
        self::SOURCE_OFFLINE,
        self::SOURCE_MANUAL,
    ];

    protected $fillable = [
        'employee_id',
        'project_id',
        'site_id',
        'attendance_date',
        'check_in_at',
        'check_out_at',
        'check_in_latitude',
        'check_in_longitude',
        'check_in_accuracy',
        'check_in_distance',
        'check_in_selfie_path',
        'check_out_latitude',
        'check_out_longitude',
        'check_out_accuracy',
        'check_out_distance',
        'shift_id',
        'scheduled_start_at',
        'scheduled_end_at',
        'working_minutes',
        'break_minutes',
        'overtime_minutes',
        'late_minutes',
        'early_departure_minutes',
        'status',
        'source',
        'device_reference',
        'notes',
        'client_event_id',
        'check_out_client_event_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'check_in_latitude' => 'decimal:7',
        'check_in_longitude' => 'decimal:7',
        'check_in_accuracy' => 'decimal:2',
        'check_in_distance' => 'decimal:2',
        'check_out_latitude' => 'decimal:7',
        'check_out_longitude' => 'decimal:7',
        'check_out_accuracy' => 'decimal:2',
        'check_out_distance' => 'decimal:2',
        'working_minutes' => 'integer',
        'break_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'late_minutes' => 'integer',
        'early_departure_minutes' => 'integer',
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /* ------------------------------------------------------------ queries */

    /** Checked in, not yet checked out. */
    public function scopeOpen($query)
    {
        return $query->whereNull('check_out_at')->whereNotNull('check_in_at');
    }

    /** Fully closed out. */
    public function scopeClosed($query)
    {
        return $query->whereNotNull('check_out_at');
    }

    public function isOpen(): bool
    {
        return $this->check_out_at === null;
    }

    /**
     * Has the scheduled day ended without a checkout?
     *
     * Derived rather than stored: an open attendance does not become a
     * "missing checkout" at any particular instant somebody is watching, and
     * writing on a read would make a GET mutate history. The persisted
     * `missing_checkout` status is set by
     * AttendanceService::flagMissingCheckouts(), which runs on the employee's
     * own `today` read — the natural moment to notice.
     */
    public function isPastScheduledEnd(): bool
    {
        if ($this->check_out_at !== null || $this->scheduled_end_at === null) {
            return false;
        }

        return $this->scheduled_end_at->isPast();
    }

    /**
     * The client-facing shape of the stored path: a boolean, never the path.
     *
     * The real value lives in `check_in_selfie_path` and is served only by
     * `GET /attendance/{id}/selfie` after a policy check. Returning it in a
     * resource would hand every reader a `storage/app/...` string they have
     * no business having and the app has no business using.
     */
    public function hasSelfie(): bool
    {
        return $this->check_in_selfie_path !== null;
    }
}
