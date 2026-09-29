<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One employee's claim for money spent.
 *
 * An expense is a personal record like an overtime request and a financial
 * one like a payroll run, and it inherits the strictest answer to each
 * question: the employee comes from the authenticated session and never from
 * the payload, the money is DECIMAL(12,2), and `status` is written by
 * ExpenseService alone — there is no endpoint anywhere that sets it
 * directly. The five states are draft -> pending -> approved, with
 * rejected and cancelled as the two ways out.
 *
 * The approval columns are the same three every other subject carries
 * (`status`, `current_approval_step`, `approval_workflow_id`) because the
 * same ApprovalWorkflowService writes them: the chain is frozen into
 * `approval_records` at submit, so editing the workflow definition later
 * never rewrites a claim already sitting in a supervisor's queue.
 */
class Expense extends Model
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

    /** The two states a claim may still be edited or withdrawn from. */
    public const OPEN = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
    ];

    protected $fillable = [
        'employee_id',
        'expense_category_id',
        'project_id',
        'site_id',
        'expense_date',
        'amount',
        'currency',
        'description',
        'status',
        'current_approval_step',
        'approval_workflow_id',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'final_approved_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'current_approval_step' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function approvalWorkflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class);
    }

    public function finalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_approved_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class);
    }

    public function approvalRecords(): HasMany
    {
        return $this->hasMany(ApprovalRecord::class, 'subject_id')
            ->where('subject_type', ApprovalRecord::TYPE_EXPENSE)
            ->orderBy('sequence');
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeStatuses($query, array $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    /* ------------------------------------------------------------- helpers */

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
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * One line for a list row: the category, the money, the day.
     */
    public function summary(): string
    {
        $day = $this->expense_date instanceof Carbon
            ? $this->expense_date->toDateString()
            : (string) $this->expense_date;

        return sprintf(
            '%s — %s %s on %s',
            $this->category?->name ?? 'Expense',
            $this->currency,
            (string) $this->amount,
            $day,
        );
    }
}
