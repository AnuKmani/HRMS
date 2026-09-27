<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A derived snapshot of one attendance day, projected onto the dimensions a
 * period report needs.
 *
 * Read TimesheetService for the full argument — the short version is that
 * `attendances` remains the source of truth for *when* somebody worked and
 * this row is the materialised view of *what that adds up to*, keyed
 * (employee, date) so regenerating a period is an idempotent upsert rather
 * than a duplicate insert.
 *
 * `status` describes the day (`open` / `complete` / `incomplete`), not an
 * approval — see the migration's note on why Phase 6 does not sign timesheets
 * off.
 */
class Timesheet extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_COMPLETE,
        self::STATUS_INCOMPLETE,
    ];

    protected $fillable = [
        'employee_id',
        'timesheet_date',
        'project_id',
        'site_id',
        'shift_id',
        'attendance_id',
        'check_in_at',
        'check_out_at',
        'working_minutes',
        'break_minutes',
        'overtime_minutes',
        'status',
        'notes',
    ];

    protected $casts = [
        'timesheet_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'working_minutes' => 'integer',
        'break_minutes' => 'integer',
        'overtime_minutes' => 'integer',
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

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /* ------------------------------------------------------------ queries */

    public function scopeStatuses($query, array $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    public function scopeBetween($query, string $from, string $to)
    {
        return $query->whereDate('timesheet_date', '>=', $from)
            ->whereDate('timesheet_date', '<=', $to);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Paid hours as a decimal, for a column that wants a number rather than
     * "7 hours 30 minutes".
     */
    public function workingHours(): float
    {
        return round($this->working_minutes / 60, 2);
    }

    public function overtimeHours(): float
    {
        return round($this->overtime_minutes / 60, 2);
    }

    /**
     * A day with no attendance row yet — leave, a holiday, or a missed punch
     * that has not been reconciled. Shown as a gap rather than omitted, so a
     * period does not quietly get shorter.
     */
    public function isUnattended(): bool
    {
        return $this->attendance_id === null;
    }
}
