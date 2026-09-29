<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of expense claim and the rules that apply to it.
 *
 * The point of this table existing is that the rules are *data*. Whether a
 * claim needs a receipt, whether it has a ceiling, whether it may be chosen
 * at all — ExpenseService reads those three answers from a row here at
 * create, update and submit time, so "Site Expense needs paper" is a
 * configuration an operator can change rather than a branch somebody has to
 * find in the code.
 *
 * `code` is the stable handle (unique) and `name` is the label a person
 * reads; a seeder looks a category up by code, so re-running it updates the
 * label instead of adding a second copy of the same category.
 */
class ExpenseCategory extends Model
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
        'status',
        'requires_receipt',
        'maximum_amount',
    ];

    protected $casts = [
        'requires_receipt' => 'boolean',
        'maximum_amount' => 'decimal:2',
    ];

    /* ----------------------------------------------------------- relations */

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
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
     * Is this claim within the category's ceiling, if it has one?
     *
     * NULL maximum means "no ceiling" — 0 would read as "nothing may be
     * claimed", which is a different sentence entirely.
     */
    public function accepts(float $amount): bool
    {
        if ($this->maximum_amount === null) {
            return true;
        }

        return $amount <= (float) $this->maximum_amount + 0.00001;
    }
}
