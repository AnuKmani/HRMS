<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One document in one person's employment file.
 *
 * Two halves to the row: what the document says (number, issue date, expiry)
 * and where its bytes live. The second half is a path on the *private* disk
 * that no API response ever echoes — the resource reports `has_file`,
 * `original_name`, `mime_type` and `size_bytes`, and the only way to the
 * bytes is `GET /api/v1/employee-documents/{document}/file` behind
 * EmployeeDocumentPolicy.
 *
 * **Five statuses, and "expiring soon" is not one of them.** `pending`,
 * `valid`, `expired`, `rejected` and `archived` are decisions somebody made
 * or facts a date has already settled; they are therefore written to the
 * row. "Expiring soon" is a *window* over `expiry_date` bounded by this
 * document type's warning period, so it changes with the clock rather than
 * with an event — storing it would mean a column that is right today and
 * wrong tomorrow. {@see self::expiryState()} computes it, and it is the same
 * computation DocumentExpiryService and the list filter use.
 *
 * Nothing here decides whether the *reader* may see it: that is
 * EmployeeDocumentPolicy's answer (your own, or `documents.manage`), asked
 * when you try.
 */
class EmployeeDocument extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VALID,
        self::STATUS_EXPIRED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * The states a document may still *become* something through — the ones
     * verification and the expiry scan are allowed to move.
     */
    public const OPEN = [
        self::STATUS_PENDING,
        self::STATUS_VALID,
        self::STATUS_EXPIRED,
    ];

    /* Expiry states, all computed rather than stored. */
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
        'document_type_id',
        'document_number',
        'issue_date',
        'expiry_date',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'status',
        'notes',
        'rejection_reason',
        'uploaded_by',
        'verified_at',
        'verified_by',
        'archived_at',
        'expiry_notified_at',
    ];

    protected $casts = [
        'document_type_id' => 'integer',
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'file_size' => 'integer',
        'verified_at' => 'datetime',
        'archived_at' => 'datetime',
        'expiry_notified_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /* ------------------------------------------------------------- helpers */

    public function hasFile(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    /**
     * Where this document stands relative to its expiry date, today.
     *
     * Order matters and is not arbitrary: archived and rejected are
     * decisions that outrank any date, so they are answered first; a
     * document past its date is expired whether or not anyone got around to
     * running the scan; only then does the warning window apply; and a
     * document with no expiry date at all is `none` rather than `valid`,
     * because "this never expires" and "this is currently fine" are
     * different statements a screen has to be able to tell apart.
     *
     * The comparison is on *dates*, not instants: an expiry of today is
     * still valid today, and reading it as a timestamp that expired at
     * midnight local time would flip a document's state depending on which
     * timezone the server happened to be in.
     */
    public function expiryState(): string
    {
        if ($this->status === self::STATUS_ARCHIVED) {
            return self::EXPIRY_NONE;
        }

        if ($this->expiry_date === null) {
            return self::EXPIRY_NONE;
        }

        $today = Carbon::today();
        $expiry = $this->expiry_date->copy()->startOfDay();

        if ($expiry->lt($today)) {
            return self::EXPIRY_PASSED;
        }

        if ($expiry->lte($today->copy()->addDays($this->warningDays()))) {
            return self::EXPIRY_SOON;
        }

        return self::EXPIRY_VALID;
    }

    /**
     * How many days are left, or null when the document does not expire.
     *
     * Negative for a document that has already passed — the sign carries the
     * meaning, so a caller never has to pair this with its own date compare.
     *
     * Whole days between two midnights, computed from timestamps rather than
     * from `diffInDays($date, $absolute)` whose second argument changed
     * meaning between Carbon major versions. This cannot drift.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        $today = Carbon::today()->getTimestamp();
        $expiry = $this->expiry_date->copy()->startOfDay()->getTimestamp();

        return (int) round(($expiry - $today) / 86400);
    }

    /**
     * How far ahead of its expiry this type warns, in days.
     */
    public function warningDays(): int
    {
        return $this->documentType?->warningDays()
            ?? max(0, (int) config('hrms.expiry.default_warning_days', 30));
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED || $this->expiryState() === self::EXPIRY_PASSED;
    }

    /**
     * May this document still be corrected, replaced or re-verified?
     *
     * Archived is the terminal state on purpose: it exists so a file with
     * historical importance can be taken out of the active list *without*
     * being deleted, and a terminal state that could be walked back out of
     * would make the archive a filter rather than a decision.
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
