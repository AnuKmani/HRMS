<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A day that is not a working day, at one of three scopes.
 *
 * Read by exactly one place — LeaveDayCalculator — plus the holiday screen.
 * Anything else that wants to know "is Tuesday a holiday?" goes through that
 * calculator rather than repeating the type/site/status rules, because two
 * copies of "inactive holidays do not count" is one copy too many.
 */
class Holiday extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_PUBLIC = 'public';

    public const TYPE_COMPANY = 'company';

    public const TYPE_SITE = 'site';

    public const TYPES = [self::TYPE_PUBLIC, self::TYPE_COMPANY, self::TYPE_SITE];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'name',
        'date',
        'type',
        'site_id',
        'description',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /* ----------------------------------------------------------- relations */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /* ------------------------------------------------------------ queries */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Everything that could apply to one calendar date.
     *
     * `site_id` is deliberately an OR rather than two queries: a public
     * holiday has no site, a company holiday has none either, and only a site
     * holiday names one — so "null or mine" is the whole rule.
     */
    public function scopeOnDate($query, string $date, ?int $siteId = null)
    {
        return $query->active()
            ->whereDate('date', $date)
            ->where(function ($q) use ($siteId) {
                $q->whereNull('site_id');

                if ($siteId !== null) {
                    $q->orWhere('site_id', $siteId);
                }
            });
    }

    /* ------------------------------------------------------------ helpers */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isScopedToSite(): bool
    {
        return $this->type === self::TYPE_SITE && $this->site_id !== null;
    }

    /**
     * The label a list row or a filter chip shows.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_SITE => 'Site',
            self::TYPE_COMPANY => 'Company',
            default => 'Public',
        };
    }
}
