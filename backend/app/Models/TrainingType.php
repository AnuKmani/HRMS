<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of training — Safety Induction, HSE, Working at Heights, First Aid.
 *
 * The vocabulary lives in a table rather than in a PHP enum or an `in:` rule
 * because it is a company's vocabulary, not this application's. Adding
 * "Confined Space Entry" is an insert; nothing has to be recompiled to
 * accept it, and no screen anywhere branches on a type's `code` — the nine
 * seeded rows are a convenience for whoever installs this, not a closed
 * enumeration.
 *
 * A *program* points at one of these (`training_programs.training_type_id`).
 * The type is the category; the program is the offering — "Working at
 * Heights, March cohort, run by Gulf Safety". Keeping them apart is what
 * lets a cohort be cancelled without losing the fact that the company runs
 * that kind of course at all.
 */
class TrainingType extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    /* ----------------------------------------------------------- relations */

    public function programs(): HasMany
    {
        return $this->hasMany(TrainingProgram::class);
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
}
