<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one employee is in their onboarding.
 *
 * The row holds the process state and nothing else. Which requirements exist
 * is OnboardingRequirement, and whether each one is met is computed by
 * OnboardingService from the documents, employee fields and bank record that
 * *are* the evidence. No counter of outstanding items is stored here: a
 * checklist count kept beside the data it counts goes stale the moment that
 * data changes without touching it, and a stale "3 of 8 complete" is worse
 * than no number at all because it is believed.
 *
 * Four states, in the order an HR desk moves through them: `draft` (opened,
 * nothing collected), `pending_documents` (the employee is uploading),
 * `hr_review` (files are in, HR is verifying), `completed` (every mandatory
 * requirement is satisfied). `completed` is written only by
 * OnboardingService::complete(), which refuses — with a 409 naming what is
 * missing — while anything mandatory is still outstanding.
 *
 * `notes` is HR's free-text note about the process, not about the person,
 * and it is deliberately not a place for sensitive values.
 */
class EmployeeOnboarding extends Model
{
    use HasFactory;

    /**
     * Deliberately singular, and singular on purpose rather than by
     * oversight: the row is *the* onboarding for one employee — the table
     * carries a unique `employee_id` — and calling it `employee_onboardings`
     * would read as though a person could be joined twice. Eloquent would
     * otherwise guess `employee_onboardings` from the class name, so the
     * name has to be stated here to be true.
     */
    protected $table = 'employee_onboarding';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_DOCUMENTS = 'pending_documents';

    public const STATUS_HR_REVIEW = 'hr_review';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_DOCUMENTS,
        self::STATUS_HR_REVIEW,
        self::STATUS_COMPLETED,
    ];

    /**
     * Statuses a record may still be *moved to* by a PUT — i.e. everything
     * except `completed`, which has its own action because it has its own
     * precondition to check first.
     */
    public const OPEN = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_DOCUMENTS,
        self::STATUS_HR_REVIEW,
    ];

    protected $fillable = [
        'employee_id',
        'status',
        'started_at',
        'completed_at',
        'completed_by',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeIncomplete($query)
    {
        return $query->where('status', '!=', self::STATUS_COMPLETED);
    }

    /* ------------------------------------------------------------- helpers */

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCompletedBy(User $user): bool
    {
        return $this->completed_by !== null && (int) $this->completed_by === (int) $user->id;
    }
}
