<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sum taken from future pay, repaid in installments.
 *
 * A salary advance is the same row with `loan_type = salary_advance` - see
 * the migration for why they are not two tables. The lifecycle:
 *
 *   draft -> pending -> approved -> active -> completed
 *                    -> rejected / cancelled (terminal)
 *
 * `approved` and `active` are separate because a loan approved with a future
 * start date is decided but not yet repaying; LoanService::activateDue()
 * moves it to `active` once the start date has arrived, which happens during
 * the next pay run rather than on a timer - there is exactly one scheduled
 * job in this application and adding a second for a transition that payroll
 * would perform anyway would be a second source of truth for one fact.
 *
 * Every mutation goes through LoanService. This class is vocabulary and
 * relations; the state machine, the installment schedule and the balance
 * arithmetic all live there, in one transaction.
 */
class Loan extends Model
{
    use HasFactory;

    public const TYPE_LOAN = 'loan';

    public const TYPE_SALARY_ADVANCE = 'salary_advance';

    public const TYPES = [
        self::TYPE_LOAN,
        self::TYPE_SALARY_ADVANCE,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses from which installments may still be claimed. */
    public const REPAYING = [
        self::STATUS_APPROVED,
        self::STATUS_ACTIVE,
    ];

    /** Statuses that are decided and never move again. */
    public const TERMINAL = [
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'employee_id',
        'loan_type',
        'reference',
        'principal_amount',
        'installment_amount',
        'number_of_installments',
        'start_date',
        'outstanding_balance',
        'status',
        'remarks',
        'created_by',
        'approved_by',
        'approved_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'principal_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'number_of_installments' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ------------------------------------------------------------ queries */

    public function scopeStatuses($query, array $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    public function scopeOfKind($query, string $type)
    {
        return $query->where('loan_type', $type);
    }

    /* ------------------------------------------------------------ helpers */

    public function isSalaryAdvance(): bool
    {
        return $this->loan_type === self::TYPE_SALARY_ADVANCE;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    public function isRepaying(): bool
    {
        return in_array($this->status, self::REPAYING, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /**
     * "Salary advance" / "Loan" as the slip and the screen print it.
     */
    public function typeLabel(): string
    {
        return $this->isSalaryAdvance() ? 'Salary advance' : 'Loan';
    }

    /**
     * How many installments are still to be taken from pay.
     *
     * A `partially_deducted` row counts as still to come: part of it has
     * been taken, and the remainder is money the schedule has not finished
     * collecting.
     */
    public function remainingInstallments(): int
    {
        return (int) $this->installments()
            ->whereIn('status', [
                LoanInstallment::STATUS_PENDING,
                LoanInstallment::STATUS_PARTIALLY_DEDUCTED,
                LoanInstallment::STATUS_DEDUCTED,
            ])
            ->count();
    }
}
