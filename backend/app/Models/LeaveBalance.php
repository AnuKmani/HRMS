<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's leave pot for one type in one year.
 *
 * Five stored numbers, one derived answer:
 *
 *     remaining = entitlement + carry_forward + adjustment - used - pending
 *
 * [remaining] is a getter and never a column, because every writer that moves
 * `used` or `adjustment` would otherwise also have to remember to move
 * `remaining` — and a balance that disagrees with its own components is worse
 * than having no balance at all.
 *
 * All mutations go through LeaveBalanceService, which runs them inside a
 * transaction and refuses to leave a negative remainder unless the leave type
 * explicitly allows one (`leave_types.allow_negative_balance`).
 */
class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'entitlement',
        'carry_forward',
        'adjustment',
        'used',
        'pending',
    ];

    protected $casts = [
        'year' => 'integer',
        'entitlement' => 'integer',
        'carry_forward' => 'integer',
        'adjustment' => 'integer',
        'used' => 'decimal:2',
        'pending' => 'decimal:2',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * What is actually available to book.
     *
     * Negative only when the leave type says so; otherwise floored at zero so
     * a corrupted `used` cannot hand time back. The floor is applied here and
     * nowhere else, so every caller sees the same number.
     */
    public function remaining(): float
    {
        $remaining = $this->entitlement
            + $this->carry_forward
            + $this->adjustment
            - (float) $this->used
            - (float) $this->pending;

        if ($remaining < 0 && ! (bool) $this->leaveType?->allow_negative_balance) {
            return 0.0;
        }

        return round($remaining, 2);
    }

    /**
     * Would booking `$days` more push this pot below zero?
     *
     * Asked *before* the write, inside the same transaction, so the refusal
     * and the reservation cannot drift apart.
     */
    public function wouldGoNegative(float $days): bool
    {
        if ((bool) $this->leaveType?->allow_negative_balance) {
            return false;
        }

        return round((float) $this->remaining() - $days, 2) < 0;
    }

    /**
     * The calendar year this pot belongs to.
     */
    public function isForYear(int $year): bool
    {
        return $this->year === $year;
    }
}
