<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_RESIGNED = 'resigned';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_RESIGNED,
        self::STATUS_TERMINATED,
        self::STATUS_ON_LEAVE,
    ];

    public const TYPES = ['permanent', 'contract', 'probation', 'internship', 'part_time'];

    protected $fillable = [
        'user_id',
        'employee_code',
        'first_name',
        'middle_name',
        'last_name',
        'photo_path',
        'email',
        'phone',
        'date_of_birth',
        'nationality',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'joining_date',
        'department_id',
        'designation_id',
        'employment_type',
        'reporting_manager_id',
        'primary_project_id',
        'primary_site_id',
        'salary',
        'employment_status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'joining_date' => 'date',
        'salary' => 'decimal:2',
    ];

    /* ----------------------------------------------------------- relations */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    /** Employees that report to this one. */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    public function primaryProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'primary_project_id');
    }

    public function primarySite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'primary_site_id');
    }

    /**
     * Full assignment history — append-only, never overwritten.
     */
    public function siteAssignments(): HasMany
    {
        return $this->hasMany(EmployeeSiteAssignment::class);
    }

    /**
     * The currently active assignment (if any).
     */
    public function currentSiteAssignment(): HasOne
    {
        return $this->hasOne(EmployeeSiteAssignment::class)
            ->where('status', EmployeeSiteAssignment::STATUS_ACTIVE)
            ->latestOfMany('start_date');
    }

    /* ---------------------------------------------------------- accessors */

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->middle_name.' '.$this->last_name);
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    public function isActive(): bool
    {
        return $this->employment_status === self::STATUS_ACTIVE;
    }
}
