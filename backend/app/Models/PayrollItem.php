<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a payslip: an earning or a deduction, and where it came from.
 *
 * Written only by PayrollService, only inside the same transaction that
 * writes the parent {@see Payroll} row, and only replaced (never edited) on
 * recalculation. Nothing in this application updates an existing item -
 * there is no endpoint for it and no service method that would.
 */
class PayrollItem extends Model
{
    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    public const TYPES = [
        self::TYPE_EARNING,
        self::TYPE_DEDUCTION,
    ];

    /* Earnings. */
    public const CODE_BASIC = 'basic';

    public const CODE_ALLOWANCE = 'allowance';

    public const CODE_OVERTIME = 'overtime';

    public const CODE_BONUS = 'bonus';

    public const CODE_ADJUSTMENT = 'adjustment';

    /* Deductions. */
    public const CODE_LOP = 'lop';

    public const CODE_LEAVE_UNPAID = 'leave_unpaid';

    public const CODE_LOAN = 'loan';

    public const CODE_ADVANCE = 'advance';

    public const CODE_OTHER = 'other';

    public const CODES = [
        self::CODE_BASIC,
        self::CODE_ALLOWANCE,
        self::CODE_OVERTIME,
        self::CODE_BONUS,
        self::CODE_ADJUSTMENT,
        self::CODE_LOP,
        self::CODE_LEAVE_UNPAID,
        self::CODE_LOAN,
        self::CODE_ADVANCE,
        self::CODE_OTHER,
    ];

    protected $fillable = [
        'payroll_id',
        'type',
        'code',
        'description',
        'quantity',
        'rate',
        'amount',
        'source_type',
        'source_id',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    /* ----------------------------------------------------------- relations */

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    /* ------------------------------------------------------------ helpers */

    public function isEarning(): bool
    {
        return $this->type === self::TYPE_EARNING;
    }

    public function isDeduction(): bool
    {
        return $this->type === self::TYPE_DEDUCTION;
    }
}
