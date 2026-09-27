<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * An approval chain definition — who has to sign off, in what order.
 *
 * The chain itself is never named in code. ApprovalWorkflowService::forSubject()
 * looks one up by subject type (a leave type's own chain first, then the
 * default for that subject) and materialises it into `approval_records` when a
 * request is submitted.
 *
 * With fewer than two steps there is nothing to approve: a single-step chain
 * is "Employee -> HR", which the seeder writes as one step because that is
 * what it is, and a zero-step chain would make every request auto-approve —
 * refused on write.
 */
class ApprovalWorkflow extends Model
{
    use HasFactory, SoftDeletes;

    public const SUBJECT_LEAVE = 'leave';

    public const SUBJECT_OVERTIME = 'overtime';

    public const SUBJECTS = [self::SUBJECT_LEAVE, self::SUBJECT_OVERTIME];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'code',
        'name',
        'subject_type',
        'description',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /* ----------------------------------------------------------- relations */

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStep::class)
            ->orderBy('sequence');
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class);
    }

    /* ------------------------------------------------------------ queries */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * The chain requests of this subject fall back to.
     */
    public function scopeDefaultFor($query, string $subjectType)
    {
        return $query->active()
            ->where('subject_type', $subjectType)
            ->where('is_default', true);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The chain in order, loaded once.
     *
     * @return Collection<int, ApprovalWorkflowStep>
     */
    public function orderedSteps(): Collection
    {
        return $this->steps->sortBy('sequence')->values();
    }

    /**
     * Every step, pre-resolved, ready to be copied into approval_records.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArrayWithSteps(): array
    {
        return $this->orderedSteps()->map(fn (ApprovalWorkflowStep $step) => $step->definition())->all();
    }
}
