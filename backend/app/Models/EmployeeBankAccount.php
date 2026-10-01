<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one employee gets paid — deliberately not part of the employee row.
 *
 * `EmployeeResource` is returned by endpoints a great many roles can reach:
 * the directory, the timesheet pickers, the approval screens. A field that
 * has to be remembered-not-to-echoed in every one of those is a field that
 * will one day be echoed, so this value does not live on `employees` at all.
 *
 * `iban`, `account_number` and `swift_bic` are `encrypted` casts: a database
 * dump, a leaked backup or a copy of `hrms_laravel` taken for debugging does
 * not hand over account numbers with it. The price is operational rather
 * than technical — **APP_KEY must not be rotated casually**, because the
 * value cannot be decrypted without it. See docs/SECURITY.md.
 *
 * Nothing here is ever written to a log. The model has no accessor that
 * would appear in an exception message, the resource that serves it is
 * EmployeeBankAccountResource and never EmployeeResource, and the two
 * routes that reach it are decided by EmployeeBankAccountPolicy rather than
 * by a `permission:` gate broad enough to be worth sharing.
 */
class EmployeeBankAccount extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /**
     * The two columns the table defaults in SQL but a brand-new instance
     * does not carry until it is read back from the database.
     *
     * `updateOrCreate` builds the model from the request payload, and a
     * payload that does not name `status` (none does — the field is not
     * submitted, only set by an operator later) would otherwise save a row
     * the database stamps `active` while the model in memory still says
     * `null`. Whichever of the two the resource happened to be handed, the
     * API would answer inconsistently with what payroll would read back, so
     * the defaults live here rather than being asserted on either side.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'currency' => 'AED',
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'employee_id',
        'account_holder_name',
        'bank_name',
        'iban',
        'account_number',
        'swift_bic',
        'currency',
        'status',
        'recorded_by',
    ];

    /**
     * Read back through the cast, never as stored text. `appends` is
     * deliberately empty and `toArray()` is not overridden: the safest
     * shape for sensitive data is one that no blanket serialization ever
     * accidentally includes, so the only way this leaves the server is the
     * resource below naming each field.
     */
    protected $casts = [
        'iban' => 'encrypted',
        'account_number' => 'encrypted',
        'swift_bic' => 'encrypted',
    ];

    /* ----------------------------------------------------------- relations */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * The last four characters of the account number, for a list that only
     * needs to confirm *which* account is on file.
     *
     * Deliberately not used by EmployeeBankAccountResource: whoever may read
     * the row may read all of it, and a half-revealed number is a
     * half-measure that costs a second code path to keep straight.
     */
    public function maskedIban(): string
    {
        $iban = (string) $this->iban;

        if (strlen($iban) <= 4) {
            return str_repeat('*', strlen($iban));
        }

        return str_repeat('*', strlen($iban) - 4).substr($iban, -4);
    }
}
