<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of document an employment file can hold, and the rules for it.
 *
 * The point of this table existing is that the rules are *data*. Whether a
 * passport carries a number, whether it has an issue date, whether it
 * expires, and how far ahead of the expiry somebody should be told — those
 * four answers are read from a row here by EmployeeDocumentService,
 * DocumentExpiryService and the onboarding checklist alike, so "Emirates IDs
 * need 60 days' warning, not 30" is an edit rather than a search across the
 * codebase.
 *
 * Nothing anywhere branches on a document type's *code*: the nine seeded
 * types below are a convenience for whoever installs this, not a closed
 * enumeration. A tenth type is a row.
 */
class DocumentType extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'name',
        'code',
        'description',
        'requires_document_number',
        'requires_issue_date',
        'requires_expiry_date',
        'expiry_warning_days',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'requires_document_number' => 'boolean',
        'requires_issue_date' => 'boolean',
        'requires_expiry_date' => 'boolean',
        'expiry_warning_days' => 'integer',
        'sort_order' => 'integer',
    ];

    /* ----------------------------------------------------------- relations */

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(OnboardingRequirement::class);
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /* ------------------------------------------------------------- helpers */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * How many days before expiry this kind of document counts as
     * "expiring soon".
     *
     * The type's own number when it has one, and the configured default
     * when it does not — zero means "nobody set this", not "never warn",
     * because a type an operator has not thought about yet is exactly the
     * one whose silent expiry would hurt.
     *
     * The window is asked for *here* rather than computed by the caller so
     * "what does expiring soon mean for this document?" has one answer in
     * the whole codebase: the scan, the list filter and the Flutter chip
     * all read the same number.
     */
    public function warningDays(): int
    {
        $days = (int) $this->expiry_warning_days;

        if ($days > 0) {
            return $days;
        }

        return max(0, (int) config('hrms.expiry.default_warning_days', 30));
    }
}
