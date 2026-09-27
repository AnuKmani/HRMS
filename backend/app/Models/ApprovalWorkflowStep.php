<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link in an approval chain, in definition form.
 *
 * When a request is submitted this is *copied* into `approval_records` — the
 * definition may be edited later without rewriting a request that is already
 * in flight, and the copy is also where a `reporting_manager` step becomes a
 * concrete employee id.
 */
class ApprovalWorkflowStep extends Model
{
    use HasFactory;

    /** "My boss" — resolved from the subject's employee at submit time. */
    public const TYPE_REPORTING_MANAGER = 'reporting_manager';

    /** Anybody holding this role, e.g. `HR Admin`. */
    public const TYPE_ROLE = 'role';

    /** Anybody holding this permission, e.g. `leave.approve`. */
    public const TYPE_PERMISSION = 'permission';

    public const TYPES = [
        self::TYPE_REPORTING_MANAGER,
        self::TYPE_ROLE,
        self::TYPE_PERMISSION,
    ];

    protected $fillable = [
        'approval_workflow_id',
        'sequence',
        'name',
        'approver_type',
        'approver_role',
        'approver_permission',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    /* ----------------------------------------------------------- relations */

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The definition, as the API accepts it back.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sequence' => $this->sequence,
            'name' => $this->name,
            'approver_type' => $this->approver_type,
            'approver_role' => $this->approver_role,
            'approver_permission' => $this->approver_permission,
        ];
    }

    /**
     * Is this step well-formed for the kind of approver it names?
     *
     * `role` needs a role, `permission` needs a permission, and
     * `reporting_manager` needs neither — validated on write so a typo'd
     * chain cannot be saved and then silently fail to route anything.
     */
    public function isWellFormed(): bool
    {
        return match ($this->approver_type) {
            self::TYPE_ROLE => $this->approver_role !== null && $this->approver_role !== '',
            self::TYPE_PERMISSION => $this->approver_permission !== null && $this->approver_permission !== '',
            self::TYPE_REPORTING_MANAGER => true,
            default => false,
        };
    }
}
