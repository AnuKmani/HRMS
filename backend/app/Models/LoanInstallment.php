<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One due repayment: which payroll row took it, and when.
 *
 * The whole anti-double-deduction rule lives in the two columns `status`
 * and `payroll_id`, which are only ever written together and only ever
 * after the row has been read with `lockForUpdate()` - see
 * PayrollService::claimInstallments(). Payroll reads `pending` rows whose
 * `due_date` falls inside the period; it never trusts a cached collection
 * from before the transaction started.
 */
class LoanInstallment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DEDUCTED = 'deducted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ADJUSTED = 'adjusted';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DEDUCTED,
        self::STATUS_SKIPPED,
        self::STATUS_ADJUSTED,
    ];

    /** The only status payroll will take money for. */
    public const CLAIMABLE = [self::STATUS_PENDING];

    protected $fillable = [
        'loan_id',
        'payroll_id',
        'sequence',
        'due_date',
        'amount',
        'status',
        'deducted_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'sequence' => 'integer',
        'amount' => 'decimal:2',
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

    public function isClaimed(): bool
    {
        return $this->status === self::STATUS_DEDUCTED && $this->payroll_id !== null;
    }
}
