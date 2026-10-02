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

    /* ---------------------------------------------------- Phase 6: leave */

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    /* -------------------------------------------------- Phase 10: file */

    /**
     * Everything in this person's employment file — passports, Emirates
     * IDs, visas, contracts.
     *
     * Relationship only; *which* of these a caller may open is
     * EmployeeDocumentPolicy's answer (your own, or `documents.manage`),
     * and the rows themselves are never included in EmployeeResource.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * Where this person is in onboarding — one row, or none yet.
     *
     * HasOne rather than HasMany because the unique index on
     * `employee_id` makes "one onboarding per employee" a database fact
     * rather than a convention two concurrent PUTs could break.
     */
    public function onboarding(): HasOne
    {
        return $this->hasOne(EmployeeOnboarding::class);
    }

    /**
     * Where this person gets paid. Deliberately its own table and its own
     * resource — see EmployeeBankAccount for why it is not columns here.
     */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(EmployeeBankAccount::class);
    }

    /**
     * Every course this person has been enrolled in, newest first.
     *
     * Relationship only; *which* of these a caller may open is
     * EmployeeTrainingPolicy's answer (your own, or `training.manage`).
     * The rows are never included in EmployeeResource — a training record
     * says something about somebody's competence and is reached through its
     * own list, behind its own permission.
     */
    public function trainings(): HasMany
    {
        return $this->hasMany(EmployeeTraining::class)->orderByDesc('enrollment_date')->orderByDesc('id');
    }

    /**
     * Every hand-over of company property to this person, including the
     * ones already closed — the history is the point, so nothing here is
     * filtered to the active row.
     */
    public function assetAssignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('assigned_date')->orderByDesc('id');
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
