<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's request for a salary certificate, and the decision on it.
 *
 *   pending -> approved -> generated
 *           -> rejected / cancelled (terminal)
 *
 * `generated` is not a separate approval - it is the record that a document
 * has actually been produced for an approved request. The PDF itself is
 * rendered on demand and never stored (see SalaryCertificatePdf), so
 * `generated_at` is written the first time one is rendered and left alone
 * afterwards. That makes it the idempotency marker for a read that has a
 * side effect, exactly as Phase 6 uses `certificate_checked_at`.
 *
 * The reference printed on the document is derived from the primary key by
 * {@see reference()} rather than stored: unique by construction, stable for
 * the life of the row, and impossible to mint twice.
 */
class SalaryCertificateRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_GENERATED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses from which a PDF may be produced. */
    public const ISSUABLE = [
        self::STATUS_APPROVED,
        self::STATUS_GENERATED,
    ];

    protected $fillable = [
        'employee_id',
        'request_date',
        'purpose',
        'status',
        'approved_by',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'generated_at',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'request_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ helpers */

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDecided(): bool
    {
        return ! $this->isPending();
    }

    public function isIssuable(): bool
    {
        return in_array($this->status, self::ISSUABLE, true);
    }

    /**
     * `SAL-CERT-000042` - derived, never minted.
     *
     * Zero padding is cosmetic (so a list sorts as it reads) and stops at
     * seven digits so the reference can never outgrow the column a reader
     * might one day store it in.
     */
    public function reference(): string
    {
        return 'SAL-CERT-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }
}
