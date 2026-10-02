<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's enrolment in one training program, and the certificate it
 * produced.
 *
 * Two halves, deliberately: the *event* (when, who ran it, what the result
 * was) and the *certificate* (its number, its dates, where its bytes live).
 * Keeping them in one row is what makes "is this person certified?" one
 * lookup rather than a join between an enrolment and a document plus the
 * hope that both are about the same course.
 *
 * **Six statuses.** `enrolled` → `scheduled` → `in_progress` → `completed`
 * are the forward path; `failed` and `cancelled` are the two ways it ends
 * without a pass; `expired` is written only by the scan, and only onto a
 * row that has already `completed` — it means *the certificate* lapsed, not
 * that the training did not happen. Every transition goes through
 * EmployeeTrainingService, so there is no client that can land a row in a
 * state the service would have refused.
 *
 * **Certificate state is computed, never stored.** `certificateExpiryState()`
 * returns `none` / `valid` / `expiring_soon` / `expired` from the date and
 * today, using `hrms.expiry.default_warning_days` as the window — a training
 * certificate is not a document type and has no per-row window of its own,
 * so the shared default is the one number the scan, the list filter and the
 * Flutter chip all read. Storing the state would mean a column that is right
 * today and wrong tomorrow.
 *
 * **The bytes are an employee document's bytes.** `certificate_path` is a
 * private path minted by EmployeeDocumentStore under
 * `storage/app/private/employee-documents/` — the same store, the same
 * sanitizer, the same five-way validation and the same
 * `path`-never-leaves-the-server rule a passport gets. There is deliberately
 * no second file store for certificates; see EmployeeDocumentStore for why
 * sharing one beats adding another.
 *
 * Nothing here decides whether the *reader* may see it: that is
 * EmployeeTrainingPolicy's answer (your own, or `training.manage`), asked
 * when you try.
 */
class EmployeeTraining extends Model
{
    use HasFactory;

    public const STATUS_ENROLLED = 'enrolled';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_ENROLLED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /**
     * The states a row may still move on from — the ones an administrator
     * may edit and the ones `complete` / `cancel` will accept.
     *
     * `cancelled` and `failed` are decided outcomes: a course somebody
     * failed is not "in progress" any more, and letting an edit walk it
     * backwards would erase the record of the attempt.
     */
    public const OPEN = [
        self::STATUS_ENROLLED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_EXPIRED,
    ];

    /**
     * The statuses that mean "this enrolment has not run yet".
     *
     * Read by the duplicate check: enrolling somebody twice into the same
     * program on the same day is refused while one of these is open, but a
     * completed course may be sat again next year.
     */
    public const LIVE = [
        self::STATUS_ENROLLED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
    ];

    /* Certificate expiry states, all computed rather than stored. */
    public const EXPIRY_NONE = 'none';

    public const EXPIRY_VALID = 'valid';

    public const EXPIRY_SOON = 'expiring_soon';

    public const EXPIRY_PASSED = 'expired';

    public const EXPIRY_STATES = [
        self::EXPIRY_NONE,
        self::EXPIRY_VALID,
        self::EXPIRY_SOON,
        self::EXPIRY_PASSED,
    ];

    protected $fillable = [
        'employee_id',
        'training_program_id',
        'enrollment_date',
        'training_date',
        'completion_date',
        'trainer',
        'status',
        'result',
        'certificate_number',
        'certificate_issue_date',
        'certificate_expiry_date',
        'certificate_path',
        'certificate_original_name',
        'certificate_mime_type',
        'certificate_size',
        'remarks',
        'created_by',
        'expiry_notified_at',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'training_program_id' => 'integer',
        'enrollment_date' => 'date',
        'training_date' => 'date',
        'completion_date' => 'date',
        'certificate_issue_date' => 'date',
        'certificate_expiry_date' => 'date',
        'certificate_size' => 'integer',
        'expiry_notified_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function trainingProgram(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE);
    }

    /* ------------------------------------------------------------- helpers */

    public function hasCertificate(): bool
    {
        return $this->certificate_path !== null && $this->certificate_path !== '';
    }

    /**
     * Where this certificate stands relative to its expiry date, today.
     *
     * The same four answers EmployeeDocument::expiryState() gives, for the
     * same reasons and in the same order: a date already passed is `expired`
     * whether or not the scheduler has caught up, the warning window comes
     * next, and no expiry date at all is `none` rather than `valid` because
     * "this never lapses" and "this is currently fine" are different
     * statements a screen has to be able to tell apart.
     *
     * Compared on *dates*, not instants: an expiry of today is still valid
     * today, and reading it as a timestamp would flip the state depending on
     * which timezone the server happened to be in.
     *
     * A row that never completed has no certificate to lapse, so `none` —
     * which is also what keeps the `expired` *status* and the `expired`
     * *state* honest about being different things: one is what happened to
     * the training, the other is what the calendar says about the paper.
     */
    public function certificateExpiryState(): string
    {
        if ($this->status === self::STATUS_CANCELLED || $this->status === self::STATUS_FAILED) {
            return self::EXPIRY_NONE;
        }

        if ($this->certificate_expiry_date === null) {
            return self::EXPIRY_NONE;
        }

        $today = Carbon::today();
        $expiry = $this->certificate_expiry_date->copy()->startOfDay();

        if ($expiry->lt($today)) {
            return self::EXPIRY_PASSED;
        }

        if ($expiry->lte($today->copy()->addDays($this->warningDays()))) {
            return self::EXPIRY_SOON;
        }

        return self::EXPIRY_VALID;
    }

    /**
     * How many days are left on the certificate, or null when it does not
     * expire. Negative for one that has already passed — the sign carries
     * the direction, so no caller pairs it with its own date comparison.
     */
    public function daysUntilCertificateExpiry(): ?int
    {
        if ($this->certificate_expiry_date === null) {
            return null;
        }

        $today = Carbon::today()->getTimestamp();
        $expiry = $this->certificate_expiry_date->copy()->startOfDay()->getTimestamp();

        return (int) round(($expiry - $today) / 86400);
    }

    /**
     * How far ahead of a certificate's expiry this system warns, in days.
     *
     * The shared default rather than a per-program number: a program is not
     * a document type, and the brief asks for the window to be reused rather
     * than re-derived. One column on `training_programs` would add a second
     * window to keep in step with `document_types.expiry_warning_days`, and
     * two windows that mean the same thing is how a report ends up
     * disagreeing with the chip beside it.
     */
    public function warningDays(): int
    {
        return max(0, (int) config('hrms.expiry.default_warning_days', 30));
    }

    /**
     * Who ran it — the enrolment's own trainer, or the program's default.
     *
     * Resolved here rather than copied onto the row when it is written, so
     * correcting a program's provider updates every cohort that did not
     * name one of its own.
     */
    public function effectiveTrainer(): ?string
    {
        return $this->trainer ?? $this->trainingProgram?->provider;
    }

    /**
     * Has this enrolment reached a decided outcome?
     *
     * `cancelled` and `failed` are terminal: the attempt happened and its
     * result is on the record, so there is nothing left for an edit to say.
     * `completed` is *not* terminal — a certificate can still be attached
     * to it, and the scan can still move it to `expired`.
     */
    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
