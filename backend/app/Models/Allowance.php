<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something an employee is paid on top of their basic salary, every month
 * or once.
 *
 * The period logic lives in `appliesTo()` rather than in the calculation, so
 * the rule that decides "does this row pay in March?" has one answer that
 * the calculator, the API and the tests all read.
 */
class Allowance extends Model
{
    use HasFactory, SoftDeletes;

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_ONE_TIME = 'one_time';

    public const FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_ONE_TIME,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CANCELLED,
    ];

    /** The codes the payslip groups by. Anything else is `other`. */
    public const KNOWN_CODES = ['housing', 'transport', 'food', 'site', 'other'];

    protected $fillable = [
        'employee_id',
        'code',
        'label',
        'amount',
        'frequency',
        'effective_from',
        'effective_to',
        'payroll_year',
        'payroll_month',
        'status',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'payroll_year' => 'integer',
        'payroll_month' => 'integer',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ queries */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Would this allowance pay into the given month?
     *
     * Four checks, in the order that lets the cheapest one stop the rest:
     *
     *  1. not cancelled;
     *  2. the effective window contains the *first* day of the period - a
     *     window is tested against the period as a whole, not against
     *     "every day of it", because an allowance that starts mid-month is
     *     meant to pay that month rather than vanish from it;
     *  3. a `one_time` allowance matches the exact period it names;
     *  4. a `monthly` allowance matches any period.
     */
    public function appliesTo(int $year, int $month, string $periodStart): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->effective_from !== null && $this->effective_from->toDateString() > $periodStart) {
            return false;
        }

        if ($this->effective_to !== null && $this->effective_to->toDateString() < $periodStart) {
            return false;
        }

        if ($this->frequency === self::FREQUENCY_ONE_TIME) {
            return $this->payroll_year === $year && $this->payroll_month === $month;
        }

        return $this->frequency === self::FREQUENCY_MONTHLY;
    }
}
