<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled repayment, and how much of it pay has actually taken.
 *
 * Four numbers live here or beside it, and confusing any two of them is the
 * bug this module exists to avoid:
 *
 *   `amount`             scheduled for this installment (the schedule's word)
 *   `deducted_amount`    taken by pay runs so far, across every run
 *   `amount - deducted_amount`  what is still owed on *this* installment
 *   loan `outstanding_balance`  what is still owed on the loan as a whole
 *
 * The anti-double-deduction rule lives in three columns rather than two
 * now. `status` says how far the row has got, `payroll_id` names the run
 * that took money from it most recently, and `deducted_amount` is the
 * running total neither of the others can express. All three are written
 * together, inside a transaction, only after the row has been re-read with
 * `lockForUpdate()` - see LoanService::claimInstallment(). What each run
 * took is recorded on that run's own `payroll_items`, which is what lets a
 * recalculation give back one month's share without disturbing another's.
 *
 * Payroll reads rows that still owe something - `pending` or
 * `partially_deducted` - and never trusts a cached collection from before
 * the transaction started.
 */
class LoanInstallment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    /** Some money has been taken, but not all of it: the rest carries forward. */
    public const STATUS_PARTIALLY_DEDUCTED = 'partially_deducted';

    public const STATUS_DEDUCTED = 'deducted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ADJUSTED = 'adjusted';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PARTIALLY_DEDUCTED,
        self::STATUS_DEDUCTED,
        self::STATUS_SKIPPED,
        self::STATUS_ADJUSTED,
    ];

    /** The statuses from which payroll may still take money. */
    public const CLAIMABLE = [
        self::STATUS_PENDING,
        self::STATUS_PARTIALLY_DEDUCTED,
    ];

    protected $fillable = [
        'loan_id',
        'payroll_id',
        'sequence',
        'due_date',
        'amount',
        'deducted_amount',
        'status',
        'deducted_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'sequence' => 'integer',
        'amount' => 'decimal:2',
        'deducted_amount' => 'decimal:2',
        'deducted_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Taken in full, by a payroll row that is still named here.
     */
    public function isClaimed(): bool
    {
        return $this->status === self::STATUS_DEDUCTED && $this->payroll_id !== null;
    }

    /**
     * Does money still owe on this installment?
     *
     * The read side of the carry-forward: `pending` rows that were never
     * reached because the run hit the net-salary floor, and
     * `partially_deducted` rows whose remainder no run has finished yet,
     * are both outstanding, and both are collected by the first run with
     * room enough - whenever that is, even if the due date is months back.
     */
    public function isOutstanding(): bool
    {
        return in_array($this->status, self::CLAIMABLE, true) && $this->remainingAmount() > 0.0;
    }

    /**
     * What is still to be taken from this installment.
     *
     * Not the same as the loan's `outstanding_balance`: this is one row of
     * the schedule, that is the whole debt.
     */
    public function remainingAmount(): float
    {
        return Money::round((float) $this->amount - (float) $this->deducted_amount);
    }

    /**
     * The status a row is in once `$deducted` has been taken in total.
     *
     * The single place the three-way decision is made, so a claim and the
     * release of that claim cannot disagree about what the row becomes:
     * zero taken is `pending` (take me again), some taken is
     * `partially_deducted` (the rest carries forward), all taken is
     * `deducted` (nothing left to ask for).
     */
    public function statusAfter(float $deducted): string
    {
        if ($deducted <= 0.0) {
            return self::STATUS_PENDING;
        }

        return $deducted >= Money::round((float) $this->amount)
            ? self::STATUS_DEDUCTED
            : self::STATUS_PARTIALLY_DEDUCTED;
    }
}
