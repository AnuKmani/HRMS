<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an employment file has to be made of before onboarding is done.
 *
 * Which requirements exist is a catalogue of rows rather than a handful of
 * booleans bolted onto `employees`, for the reason the table's own migration
 * sets out: a boolean cannot say *why* something is outstanding, and adding
 * a tenth requirement should not mean migrating a table every query reads.
 *
 * `kind` is how this row says what would satisfy it — `document` (an
 * `employee_documents` row of `document_type_id` with status `valid`),
 * `data` (every column in `employee_fields` non-empty on the employee), or
 * `bank` (a row in `employee_bank_accounts`). The judgement itself lives in
 * OnboardingService so that "is this satisfied?" has one answer shared by
 * the checklist, the missing-document filter and the completion check.
 */
class OnboardingRequirement extends Model
{
    use HasFactory;

    public const KIND_DOCUMENT = 'document';

    public const KIND_DATA = 'data';

    public const KIND_BANK = 'bank';

    public const KINDS = [
        self::KIND_DOCUMENT,
        self::KIND_DATA,
        self::KIND_BANK,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /**
     * The five states a requirement can be in, in the order an HR desk
     * triages them. Computed, never stored — see OnboardingService.
     *
     * `satisfied`  every mandatory part is in place;
     * `pending_verification` a document arrived, HR has not signed it off;
     * `rejected`   the document of this type was refused, so somebody has
     *              to upload a different one;
     * `expired`    the document on file has passed its expiry date;
     * `missing`    nothing has been filed at all.
     */
    public const STATE_SATISFIED = 'satisfied';

    public const STATE_PENDING = 'pending_verification';

    public const STATE_REJECTED = 'rejected';

    public const STATE_EXPIRED = 'expired';

    public const STATE_MISSING = 'missing';

    public const STATES = [
        self::STATE_SATISFIED,
        self::STATE_PENDING,
        self::STATE_REJECTED,
        self::STATE_EXPIRED,
        self::STATE_MISSING,
    ];

    protected $fillable = [
        'name',
        'code',
        'description',
        'kind',
        'document_type_id',
        'employee_fields',
        'is_mandatory',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'document_type_id' => 'integer',
        'is_mandatory' => 'boolean',
        'sort_order' => 'integer',
    ];

    /* ----------------------------------------------------------- relations */

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /* ------------------------------------------------------------- helpers */

    public function isMandatory(): bool
    {
        return (bool) $this->is_mandatory;
    }

    public function isDocumentRequirement(): bool
    {
        return $this->kind === self::KIND_DOCUMENT;
    }

    /**
     * The employee column names this requirement reads, for kind = `data`.
     *
     * Parsed here rather than at every call site: the column is a comma
     * list, and "a list of identifiers" deserves one splitter rather than
     * four `explode()`s that each forget to trim.
     *
     * @return array<int, string>
     */
    public function checkedFields(): array
    {
        if ($this->kind !== self::KIND_DATA) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $this->employee_fields))));
    }
}
