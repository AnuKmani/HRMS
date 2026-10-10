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
        'main_contractor',
        'created_by',
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
        'progress_percent' => 'decimal:2',
        'progress_updated_at' => 'datetime',
    ];

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subcontractors(): HasMany
    {
        return $this->hasMany(ProjectSubcontractor::class)->orderBy('status')->orderBy('name');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(ProjectProgressUpdate::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function paymentMilestones(): HasMany
    {
        return $this->hasMany(ProjectPaymentMilestone::class)->orderBy('trigger_percent')->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class)->latest();
    }

    /**
     * Where progress should be today if work were spread evenly between
     * start and end; null without both dates.
     */
    public function expectedPercent(): ?float
    {
        if ($this->start_date === null || $this->end_date === null || $this->end_date->lte($this->start_date)) {
            return null;
        }

        $total = $this->start_date->diffInDays($this->end_date);
        $elapsed = $this->start_date->diffInDays(now()->startOfDay(), false);

        return round(max(0, min(100, $elapsed / $total * 100)), 2);
    }

    /** Behind by more than 5 points of where it should be by now. */
    public function isBehindSchedule(): bool
    {
        $expected = $this->expectedPercent();

        return $expected !== null
            && ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)
            && (float) $this->progress_percent + 5 < $expected;
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
