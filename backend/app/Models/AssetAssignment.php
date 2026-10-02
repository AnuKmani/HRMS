<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One hand-over of one asset to one person — and, if there was one, the
 * hand-back.
 *
 * **This table is append-only.** A return writes its three fields onto the
 * existing row and flips `status` to `returned`; it never deletes the row,
 * never opens a replacement and never rewrites `assigned_condition`. So
 * "who had this laptop in March and what state did it leave in?" is still
 * answerable in November from the same rows the March answer came from,
 * which is the entire reason the table exists rather than an
 * `asset.employee_id` column with a `returned_at` next to it.
 *
 * One row is one *relationship* — `active` while the asset is out, `returned`
 * once it is back. At most one row per asset is `active` at any time, and
 * that invariant is enforced inside a transaction under `lockForUpdate()`
 * by AssetService::assign() rather than by a unique index, because MySQL
 * has no partial unique index to express it with. See the migration for
 * why that is a real guarantee rather than a hopeful one.
 *
 * `assigned_condition` records what condition the asset was *handed out*
 * in — an important number, because a tool that came back broken and a tool
 * that went out broken are two very different stories.
 */
class AssetAssignment extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETURNED = 'returned';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RETURNED,
    ];

    protected $fillable = [
        'asset_id',
        'employee_id',
        'assigned_date',
        'expected_return_date',
        'returned_date',
        'assigned_condition',
        'returned_condition',
        'assigned_by',
        'returned_by',
        'status',
        'remarks',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'employee_id' => 'integer',
        'assigned_date' => 'date',
        'expected_return_date' => 'date',
        'returned_date' => 'date',
        'assigned_by' => 'integer',
        'returned_by' => 'integer',
    ];

    /* ----------------------------------------------------------- relations */

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeReturned($query)
    {
        return $query->where('status', self::STATUS_RETURNED);
    }

    /* ------------------------------------------------------------- helpers */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /**
     * Days the asset has been out, or null while it is still out.
     *
     * A whole number of days between two midnights, computed from
     * timestamps rather than from `diffInDays(..., $absolute)` whose second
     * argument changed meaning between Carbon major versions.
     */
    public function daysOut(): ?int
    {
        if ($this->returned_date === null) {
            return null;
        }

        $from = $this->assigned_date->copy()->startOfDay()->getTimestamp();
        $to = $this->returned_date->copy()->startOfDay()->getTimestamp();

        return (int) round(($to - $from) / 86400);
    }

    /**
     * Is this hand-back overdue against the date it was promised back?
     *
     * False rather than null when no return date was ever expected: an
     * open-ended loan is not late, it simply has no deadline.
     */
    public function isOverdue(): bool
    {
        if ($this->expected_return_date === null) {
            return false;
        }

        if ($this->isReturned()) {
            return $this->returned_date->startOfDay()
                ->gt($this->expected_return_date->copy()->startOfDay());
        }

        return $this->expected_return_date->copy()->startOfDay()
            ->lt(Carbon::today());
    }
}
