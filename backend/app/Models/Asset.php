<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of company property, and where it sits in its lifecycle.
 *
 * **`status` and `current_condition` are two different facts and are never
 * allowed to stand in for each other.** The status says where the asset is
 * in its life — is it on a shelf, on somebody's desk, in the workshop, gone
 * — and therefore what the *next legal action* is. The condition says what
 * state it is physically in. An asset can be `assigned` and `poor` at the
 * same time, and it usually is; collapsing them would lose exactly the
 * detail a return exists to record.
 *
 * Neither is ever written by a controller. AssetService is the only thing
 * that moves a status, because "which sequence of calls can turn an
 * available laptop into a retired one, and what has to be true at each
 * step" has to have exactly one answer.
 *
 * `asset_code` is the asset's permanent identity: unique, assigned once,
 * never reissued — a retired laptop's code does not go to the next one,
 * because that is how an audit trail stops being an audit trail. It is a
 * plain string precisely so a barcode or QR label can carry exactly the
 * value the screen shows (docs/API_DOCUMENTATION.md notes the future use;
 * nothing in this phase prints a label).
 *
 * `purchase_cost` is money in DECIMAL(12,2) and is withheld by
 * AssetResource from any reader without `assets.manage`.
 */
class Asset extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_DAMAGED = 'damaged';

    public const STATUS_LOST = 'lost';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ASSIGNED,
        self::STATUS_MAINTENANCE,
        self::STATUS_DAMAGED,
        self::STATUS_LOST,
        self::STATUS_RETIRED,
    ];

    /**
     * The transitions AssetService::changeStatus() will accept, written as
     * "from => every state it may become".
     *
     * A map rather than a clever one-line rule because the interesting
     * cases are exceptions:
     *
     *  - **`assigned` cannot become `available`.** That is exactly what a
     *    hand-back is, and it has its own action because it must also close
     *    the assignment row, stamp who took it back and record the returned
     *    condition. A status change that left the assignment open would
     *    produce an asset nobody could assign (an active row) and nobody
     *    could return (no longer marked assigned).
     *  - **`assigned` may go to `maintenance`, `damaged` or `lost`** — the
     *    laptop that came back broken, the tool that walked off a site —
     *    and the assignment stays open, because the person still has it and
     *    the honest record is "out, and now in this state".
     *  - **`lost` is not terminal; `retired` is.** A found asset comes back
     *    to the shelf or to the workshop; an asset written off does not,
     *    and a status that could be walked backwards would make retirement
     *    a filter rather than a decision.
     *  - **`available` is the hub**: anything on the shelf can be sent for
     *    maintenance, marked damaged or lost, retired — and can of course
     *    be assigned, which is the one thing it may not become through
     *    *this* method.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_AVAILABLE => [
            self::STATUS_ASSIGNED,
            self::STATUS_MAINTENANCE,
            self::STATUS_DAMAGED,
            self::STATUS_LOST,
            self::STATUS_RETIRED,
        ],
        self::STATUS_ASSIGNED => [
            self::STATUS_MAINTENANCE,
            self::STATUS_DAMAGED,
            self::STATUS_LOST,
        ],
        self::STATUS_MAINTENANCE => [
            self::STATUS_AVAILABLE,
            self::STATUS_DAMAGED,
            self::STATUS_LOST,
            self::STATUS_RETIRED,
        ],
        self::STATUS_DAMAGED => [
            self::STATUS_AVAILABLE,
            self::STATUS_MAINTENANCE,
            self::STATUS_LOST,
            self::STATUS_RETIRED,
        ],
        self::STATUS_LOST => [
            self::STATUS_AVAILABLE,
            self::STATUS_MAINTENANCE,
            self::STATUS_RETIRED,
        ],
        self::STATUS_RETIRED => [],
    ];

    /* Physical conditions — deliberately a different vocabulary from the
       lifecycle statuses above, and deliberately short. */
    public const CONDITION_NEW = 'new';

    public const CONDITION_GOOD = 'good';

    public const CONDITION_FAIR = 'fair';

    public const CONDITION_POOR = 'poor';

    public const CONDITIONS = [
        self::CONDITION_NEW,
        self::CONDITION_GOOD,
        self::CONDITION_FAIR,
        self::CONDITION_POOR,
    ];

    protected $fillable = [
        'asset_code',
        'asset_type_id',
        'name',
        'description',
        'serial_number',
        'manufacturer',
        'model',
        'purchase_date',
        'purchase_cost',
        'current_condition',
        'status',
        'notes',
    ];

    protected $casts = [
        'asset_type_id' => 'integer',
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /**
     * Every hand-over, oldest first. Never filtered to the active one —
     * the history *is* the point, and a relation that hid the closed rows
     * would be the first step towards overwriting them.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('assigned_date')->orderByDesc('id');
    }

    public function activeAssignment(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)
            ->where('status', AssetAssignment::STATUS_ACTIVE);
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /* ------------------------------------------------------------- helpers */

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function isAssigned(): bool
    {
        return $this->status === self::STATUS_ASSIGNED;
    }

    /**
     * May this asset be handed to somebody right now?
     *
     * `retired` is never assignable; `lost` and `damaged` are not either,
     * because handing out a laptop nobody can find is not an assignment.
     */
    public function isAssignable(): bool
    {
        return in_array($this->status, [self::STATUS_AVAILABLE], true);
    }

    /**
     * May this asset's status be walked to `$to`?
     *
     * Asked by AssetService, which is the only writer — a controller that
     * set `status` directly would skip both this rule and the row lock.
     */
    public function canTransitionTo(string $to): bool
    {
        if ($to === $this->status) {
            // Restating the current status is not a transition. It is
            // refused rather than accepted as a no-op, because "the update
            // succeeded" and "nothing happened" should not be the same
            // response for a caller deciding what to do next.
            return false;
        }

        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
