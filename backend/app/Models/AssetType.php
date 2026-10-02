<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of company asset — Laptop, Safety Equipment, Measuring Equipment.
 *
 * The same argument, and the same shape, as TrainingType: "Tool" and
 * "Measuring Equipment" are a company's words for its property, so they are
 * rows rather than a validation `in:` list nobody could extend. Every asset
 * points at one of these; no screen anywhere branches on a type's code.
 */
class AssetType extends Model
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

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
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
