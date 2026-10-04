<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PLANNED = 'planned';

    /** Shown as "Ongoing". */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HANDING_OVER = 'handing_over';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_ACTIVE,
        self::STATUS_HANDING_OVER,
        self::STATUS_ON_HOLD,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'name',
        'code',
        'client',
        'description',
        'location',
        'project_manager_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    /** Every milestone, soonest first. */
    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class)->orderBy('event_date')->orderBy('id');
    }

    /** Milestones from today on — what the project list shows. */
    public function upcomingEvents(): HasMany
    {
        return $this->hasMany(ProjectEvent::class)
            ->where('event_date', '>=', now()->toDateString())
            ->orderBy('event_date')
            ->orderBy('id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'primary_project_id');
    }

    public function siteAssignments(): HasMany
    {
        return $this->hasMany(EmployeeSiteAssignment::class);
    }
}
