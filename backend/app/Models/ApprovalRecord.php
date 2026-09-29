<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A materialised approval step: one row per (subject, sequence).
 *
 * The subject columns are polymorphic — a row belongs to a leave request, an
 * overtime request or an expense claim — because a step cannot have two
 * foreign keys and a table per subject would mean a third copy of the same
 * approval logic. The constants below are the only strings allowed there.
 *
 * `acted_by` is a User id: approvals are an act performed by a *session*, and
 * the employee behind that session is reachable through the user. Recording
 * the user (not the employee) is what makes "who clicked approve?" answerable
 * even when the approver has no employee record of their own.
 */
class ApprovalRecord extends Model
{
    use HasFactory;

    public const TYPE_LEAVE = 'leave_request';

    public const TYPE_OVERTIME = 'overtime_request';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [self::TYPE_LEAVE, self::TYPE_OVERTIME, self::TYPE_EXPENSE];

    public const STATUS_WAITING = 'waiting';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'subject_type',
        'subject_id',
        'approval_workflow_id',
        'sequence',
        'name',
        'approver_type',
        'approver_role',
        'approver_permission',
        'approver_employee_id',
        'status',
        'acted_by',
        'acted_at',
        'remarks',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'acted_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function resolvedApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }

    /**
     * The leave request this step belongs to, or null for any other subject.
     *
     * Manual rather than a polymorphic relation: Laravel's morph would need a
     * `subject_type` value it recognises and a matching `subject()` method on
     * every parent, and there are three subjects today.
     */
    public function leaveRequest(): ?LeaveRequest
    {
        return $this->subject_type === self::TYPE_LEAVE
            ? LeaveRequest::query()->find($this->subject_id)
            : null;
    }

    public function overtimeRequest(): ?OvertimeRequest
    {
        return $this->subject_type === self::TYPE_OVERTIME
            ? OvertimeRequest::query()->find($this->subject_id)
            : null;
    }

    public function expense(): ?Expense
    {
        return $this->subject_type === self::TYPE_EXPENSE
            ? Expense::query()->find($this->subject_id)
            : null;
    }

    /* ------------------------------------------------------------ queries */

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * The step a subject is currently waiting on.
     */
    public function scopeForSubject($query, string $subjectType, int $subjectId)
    {
        return $query->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);
    }

    /* ------------------------------------------------------------ helpers */

    public function isCurrent(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_SKIPPED], true);
    }
}
