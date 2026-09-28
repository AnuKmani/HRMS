<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee, one month, every figure that made up their pay.
 *
 * This row holds the totals and `payroll_items` holds the reasons; both are
 * written together by PayrollService inside a single transaction, so the two
 * can never disagree. Nothing here is client-supplied: a request says
 * "process March", and the service decides what March pays from
 * `employees.salary`, `allowances`, approved overtime, approved adjustments,
 * Loss of Pay leave, unpaid leave and due loan installments.
 *
 * The status vocabulary is a one-way street:
 *
 *   draft -> calculated -> reviewed -> processed -> locked
 *
 * Only `draft` and `calculated` may be recalculated. From `reviewed` onwards
 * the row is a record of a decision somebody made, and silently recomputing
 * it would destroy the thing being reviewed. Every refusal is a 409 naming
 * the state, not a 403 pretending the caller was never allowed to ask.
 *
 * `locked` is terminal. There is deliberately no `unlock` endpoint: the
 * point of locking is that nothing moves it, and a permission-gated escape
 * hatch would make the lock a suggestion.
 */
class Payroll extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_LOCKED = 'locked';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CALCULATED,
        self::STATUS_REVIEWED,
        self::STATUS_PROCESSED,
        self::STATUS_LOCKED,
    ];

    /** The only statuses a recalculation is allowed to touch. */
    public const RECALCULABLE = [
        self::STATUS_DRAFT,
        self::STATUS_CALCULATED,
    ];

    protected $fillable = [
        'employee_id',
        'payroll_year',
        'payroll_month',
        'period_start',
        'period_end',
        'basic_salary',
        'total_allowances',
        'overtime_amount',
        'bonus_amount',
        'gross_salary',
        'lop_days',
        'lop_divisor',
        'overtime_minutes',
        'lop_amount',
        'loan_deduction',
        'advance_deduction',
        'other_deductions',
        'total_deductions',
        'net_salary',
        'status',
        'reviewed_at',
        'reviewed_by',
        'processed_at',
        'processed_by',
        'locked_at',
        'locked_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payroll_year' => 'integer',
        'payroll_month' => 'integer',
        // Money is DECIMAL in every column, so each figure is echoed back as
        // the same two-place string MySQL holds. Casting to `decimal:2`
        // rather than `float` keeps the JSON response a string the client
        // parses deliberately, instead of a double it might re-round.
        'basic_salary' => 'decimal:2',
        'total_allowances' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'lop_days' => 'decimal:2',
        'lop_divisor' => 'decimal:4',
        'overtime_minutes' => 'integer',
        'lop_amount' => 'decimal:2',
        'loan_deduction' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'processed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Installments this payroll row claimed - written by LoanService and
     * released by a recalculation, never by anything else.
     */
    public function loanInstallments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class);
    }

    /* ------------------------------------------------------------ queries */

    public function scopePeriod($query, int $year, int $month)
    {
        return $query->where('payroll_year', $year)->where('payroll_month', $month);
    }

    public function scopeStatuses($query, array $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The three states a row can no longer be changed from. Named so a
     * policy can ask the question without importing the array.
     */
    public function isImmutable(): bool
    {
        return ! in_array($this->status, self::RECALCULABLE, true);
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    /**
     * "March 2026" for a heading - the month comes from the row rather than
     * from the clock so a slip read next year still names the month it pays.
     */
    public function periodLabel(): string
    {
        $names = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        return ($names[$this->payroll_month] ?? (string) $this->payroll_month)
            .' '.$this->payroll_year;
    }
}
