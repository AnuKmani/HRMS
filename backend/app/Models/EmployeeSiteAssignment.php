<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only historical record of an employee being placed on a site.
 *
 * Moving an employee to a new site CLOSES the current row (end_date + status)
 * and INSERTS a new one. Rows are never updated in place or deleted.
 */
class EmployeeSiteAssignment extends Model
{
    use HasFactory;

    public const TYPE_PRIMARY = 'primary';

    public const TYPE_TEMPORARY = 'temporary';

    public const TYPE_ADDITIONAL = 'additional';

    public const TYPES = [
        self::TYPE_PRIMARY,
        self::TYPE_TEMPORARY,
        self::TYPE_ADDITIONAL,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ENDED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'employee_id',
        'project_id',
        'site_id',
        'assignment_type',
        'start_date',
        'end_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Close this assignment without destroying it.
     */
    public function end(?string $onDate = null): bool
    {
        $this->status = self::STATUS_ENDED;
        $this->end_date = $onDate ?? now()->toDateString();

        return $this->save();
    }
}
