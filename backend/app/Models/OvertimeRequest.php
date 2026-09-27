<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for extra minutes worked, and the decision on it.
 *
 * `requested_minutes` and `approved_minutes` are kept apart on purpose: a
 * supervisor may grant part of what was asked for, and collapsing the two
 * would either lose the original claim or overstate the grant.
 *
 * `payroll_eligible` is written only when a chain completes in `approved`,
 * by ApprovalWorkflowService. Nothing in Phase 6 reads it to pay anybody —
 * it is the flag payroll will look for in its own phase.
 */
class OvertimeRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The default the column carries, mirrored onto the model so a freshly
     * built row is never *absent* rather than false.
     *
     * Without this, `new OvertimeRequest([...])` leaves `payroll_eligible`
     * out of its attributes, the INSERT picks up the column default on the
     * server, and the model that goes straight into the response still has
     * nothing — so `payroll_eligible: null` is emitted for a claim that is
     * unambiguously not payable. A client branching on `null` and a client
     * branching on `false` are branching on different things, and only one of
     * them is the answer the database holds.
     */
    protected $attributes = [
        'payroll_eligible' => false,
    ];

    protected $fillable = [
        'employee_id',
        'overtime_date',
        'project_id',
        'site_id',
        'attendance_id',
        'requested_minutes',
        'approved_minutes',
        'reason',
        'status',
        'submitted_at',
        'cancelled_at',
        'rejected_at',
        'approved_at',
        'approved_by',
        'rejected_by',
        'remarks',
        'current_approval_step',
        'approval_workflow_id',
        'payroll_eligible',
    ];

    protected $casts = [
        'overtime_date' => 'date',
        'requested_minutes' => 'integer',
        'approved_minutes' => 'integer',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rejected_at' => 'datetime',
        'approved_at' => 'datetime',
        'current_approval_step' => 'integer',
        'payroll_eligible' => 'boolean',
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

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function approvalWorkflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalRecords(): HasMany
    {
        return $this->hasMany(ApprovalRecord::class, 'subject_id')
            ->where('subject_type', ApprovalRecord::TYPE_OVERTIME)
            ->orderBy('sequence');
    }

    /* ------------------------------------------------------------ queries */

    public function scopeStatuses($query, array $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    /* ------------------------------------------------------------ helpers */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    /**
     * Only a fully approved request may ever reach payroll.
     *
     * The getter restates the column rather than re-deriving it from status:
     * the column is the record of what was decided, and a request whose
     * approval was later reversed would flip the column, not the rule.
     */
    public function isPayrollEligible(): bool
    {
        return (bool) $this->payroll_eligible;
    }

    public function requestedHours(): float
    {
        return round($this->requested_minutes / 60, 2);
    }

    public function approvedHours(): ?float
    {
        return $this->approved_minutes === null
            ? null
            : round($this->approved_minutes / 60, 2);
    }
}
