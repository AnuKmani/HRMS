<?php

namespace App\Models;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A configurable leave type.
 *
 * Nothing in the leave subsystem reads a number that is not on this row (or,
 * failing that, in `settings`). Entitlements, carry-forward, request caps,
 * certificate rules and the paid/unpaid classification all live here so that
 * changing a policy is an UPDATE rather than a deploy — see docs/DATABASE.md
 * for how the values combine into a balance.
 */
class LeaveType extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'name',
        'code',
        'description',
        'entitlement_days',
        'carry_forward_enabled',
        'carry_forward_limit',
        'maximum_days_per_request',
        'is_paid',
        'requires_document',
        'document_deadline_days',
        'allow_negative_balance',
        'status',
        'approval_workflow_id',
    ];

    protected $casts = [
        'entitlement_days' => 'integer',
        'carry_forward_enabled' => 'boolean',
        'carry_forward_limit' => 'integer',
        'maximum_days_per_request' => 'integer',
        'is_paid' => 'boolean',
        'requires_document' => 'boolean',
        'document_deadline_days' => 'integer',
        'allow_negative_balance' => 'boolean',
    ];

    /* ----------------------------------------------------------- relations */

    public function approvalWorkflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /* ------------------------------------------------------------ queries */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The deadline, in days, for a document this type demands.
     *
     * 0 on the row means "no opinion" — the organisation-wide
     * `leave.sick_certificate_deadline_days` setting answers instead. That
     * ordering (type first, setting second) is what lets one sick leave
     * policy be tuned for a branch without inventing a second leave type.
     */
    public function documentDeadlineDays(SettingsService $settings): int
    {
        if ($this->document_deadline_days > 0) {
            return $this->document_deadline_days;
        }

        return max(1, $settings->int('leave.sick_certificate_deadline_days', 2));
    }

    /**
     * A request cap of 0 means no cap at all.
     */
    public function capsRequests(): bool
    {
        return $this->maximum_days_per_request > 0;
    }
}
