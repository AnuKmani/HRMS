<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One absence, from request to decision.
 *
 * Every state transition lives in LeaveRequestService — this class holds the
 * vocabulary and the relations, not the rules. The status constants below are
 * the complete set; `lop` is a terminal state like `approved` rather than a
 * flag bolted onto one, because a request that has been converted to Loss of
 * Pay has been *decided*, differently.
 */
class LeaveRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LOP = 'lop';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_LOP,
    ];

    /**
     * Statuses that still occupy the dates they cover.
     *
     * Used by the overlap check: a rejected or cancelled request frees the
     * days again, while a draft, a pending one, an approved one and a request
     * already converted to LOP all continue to hold them — otherwise the same
     * fortnight could be booked twice and paid for twice.
     */
    public const OCCUPYING = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_LOP,
    ];

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'site_id',
        'start_date',
        'end_date',
        'requested_days',
        'reason',
        'status',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'approved_by',
        'rejected_by',
        'remarks',
        'current_approval_step',
        'approval_workflow_id',
        'certificate_path',
        'certificate_original_name',
        'certificate_mime',
        'certificate_size',
        'certificate_uploaded_at',
        'certificate_due_at',
        'certificate_checked_at',
        'lop_days',
        'lop_reason',
        'lop_applied_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'requested_days' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'current_approval_step' => 'integer',
        'certificate_uploaded_at' => 'datetime',
        'certificate_due_at' => 'date',
        'certificate_checked_at' => 'datetime',
        'certificate_size' => 'integer',
        'lop_days' => 'decimal:2',
        'lop_applied_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function approvalWorkflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * The materialised chain, in order. Empty for a request that has never
     * been submitted — a draft has no history to show.
     */
    public function approvalRecords(): HasMany
    {
        return $this->hasMany(ApprovalRecord::class, 'subject_id')
            ->where('subject_type', ApprovalRecord::TYPE_LEAVE)
            ->orderBy('sequence');
    }

    /* ------------------------------------------------------------ queries */

    public function scopeStatuses($query, array $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->whereYear('start_date', $year);
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
     * The days are paid or not — read off the type, never assumed.
     */
    public function isPaid(): bool
    {
        return (bool) $this->leaveType?->is_paid;
    }

    /**
     * Does this type demand a medical certificate, and has one been filed?
     *
     * Two different questions, deliberately kept apart: `requiresDocument()`
     * is a property of the *policy*, `hasCertificate()` is a property of the
     * *record*. The deadline job needs both and must not conflate them.
     */
    public function requiresDocument(): bool
    {
        return (bool) $this->leaveType?->requires_document;
    }

    public function hasCertificate(): bool
    {
        return $this->certificate_path !== null;
    }

    public function certificateIsOverdue(): bool
    {
        return $this->certificate_due_at !== null
            && $this->certificate_due_at->lte(today());
    }

    /**
     * The whole absence as one sentence for a list row.
     */
    public function summary(): string
    {
        $start = $this->start_date?->toDateString() ?? '';
        $end = $this->end_date?->toDateString() ?? '';

        return $start === $end || $end === '' ? $start : "{$start} → {$end}";
    }
}
