<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A one-off addition to or subtraction from one month's pay.
 *
 * Bonuses, other deductions and manual adjustments share this table - see
 * the migration for why three tables would have meant three copies of the
 * same approval flow. What matters here is the sign rule:
 *
 *   bonus            amount must be positive; it is an earning
 *   other_deduction  amount must be positive; the *type* supplies the minus
 *   adjustment       amount may be positive or negative, never zero
 *
 * So `amount` is stored as an unsigned-looking figure for the first two and
 * carries its own sign for the third, and `signedAmount()` is the only place
 * that turns one into the other. Nothing adds `amount` directly.
 */
class PayrollAdjustment extends Model
{
    use HasFactory;

    public const TYPE_BONUS = 'bonus';

    public const TYPE_OTHER_DEDUCTION = 'other_deduction';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_BONUS,
        self::TYPE_OTHER_DEDUCTION,
        self::TYPE_ADJUSTMENT,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Only these ever reach a pay run. */
    public const PAYABLE = [self::STATUS_APPROVED];

    protected $fillable = [
        'employee_id',
        'payroll_year',
        'payroll_month',
        'type',
        'description',
        'amount',
        'status',
        'approved_by',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payroll_year' => 'integer',
        'payroll_month' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ queries */

    public function scopePayable($query)
    {
        return $query->whereIn('status', self::PAYABLE);
    }

    public function scopePeriod($query, int $year, int $month)
    {
        return $query->where('payroll_year', $year)->where('payroll_month', $month);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Does this row add to the pay or subtract from it?
     *
     * For `adjustment` the sign is the amount's own; for the other two the
     * type decides, which is why a bonus is always stored positive.
     */
    public function signedAmount(): float
    {
        $amount = (float) $this->amount;

        return match ($this->type) {
            self::TYPE_BONUS => max(0.0, $amount),
            self::TYPE_OTHER_DEDUCTION => -max(0.0, $amount),
            default => $amount,
        };
    }

    /**
     * Why an amount cannot be accepted for this type, as a field => message
     * map. Empty when it can.
     *
     * Kept separate from {@see assertAmountIsUsable()} so the FormRequest can
     * attach the messages to the validator while the service throws them: a
     * `ValidationException` raised inside a validator's `after()` callback is
     * caught somewhere other than where it was raised, and an error message
     * that sometimes surfaces as a 422 and sometimes as a 500 is worse than
     * two three-line call sites.
     *
     * @return array<string, string>
     */
    public static function amountProblems(string $type, float $amount): array
    {
        if ($type === self::TYPE_BONUS || $type === self::TYPE_OTHER_DEDUCTION) {
            return $amount <= 0
                ? ['amount' => 'A '.$type.' amount must be greater than zero.']
                : [];
        }

        if ($type === self::TYPE_ADJUSTMENT) {
            if ($amount == 0.0) {
                return ['amount' => 'An adjustment must not be zero - approve it as a bonus or a deduction instead.'];
            }

            if (abs($amount) > 999999999.99) {
                return ['amount' => 'An adjustment amount cannot exceed 999999999.99.'];
            }

            return [];
        }

        return ['type' => 'Unknown adjustment type.'];
    }

    /**
     * Refuse an amount the type cannot carry.
     *
     * Called from PayrollService, because a row can reach the calculation
     * through a seeder, a tinker session or a direct model write, and "no
     * negative bonuses" must hold for all three.
     *
     * @throws ValidationException
     */
    public static function assertAmountIsUsable(string $type, float $amount): void
    {
        $problems = self::amountProblems($type, $amount);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }
}
