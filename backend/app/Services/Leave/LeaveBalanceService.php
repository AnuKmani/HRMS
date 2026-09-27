<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to `leave_balances`, and the only reader of its rule.
 *
 * The rule, in one line:
 *
 *     remaining = entitlement + carry_forward + adjustment - used - pending
 *
 * and a request is refused whenever that would go below zero — unless the
 * leave type says `allow_negative_balance`, which is how Unpaid Leave (no
 * pot to exhaust) stays usable instead of refusing every request by
 * construction.
 *
 * Four movements, deliberately named for what they mean rather than for
 * arithmetic:
 *
 *   reserve()  a request was submitted. Days are *pending*, not used —
 *              two overlapping requests can then both be told the truth
 *              about what is left.
 *   commit()   it was approved. Pending becomes used.
 *   release()  it was rejected, cancelled, or converted to LOP. The
 *              reservation goes back.
 *   adjust()   HR corrected a pot by hand (carry-forward, entitlement fix).
 *
 * Each one re-reads its row under `lockForUpdate()` inside its own
 * transaction, so two approvals racing on the same balance serialise instead
 * of both seeing "enough left" and both debiting it. Nested inside the
 * caller's transaction, which is exactly what should happen: the balance
 * move and the status change succeed or fail together.
 *
 * Nothing here knows what a leave *request* is. That is LeaveRequestService's
 * job — this class only moves numbers.
 */
final class LeaveBalanceService
{
    /**
     * Every pot this employee should have for the year, created on first read.
     *
     * Materialised rather than virtual: a balance with no row would have no
     * entitlement to show, and a screen that rendered "0 available" for an
     * active Annual Leave type would be lying about the policy.
     *
     * @return Collection<int, LeaveBalance>
     */
    public function forYear(Employee $employee, int $year): Collection
    {
        return LeaveType::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type) => $this->find($employee, $type, $year));
    }

    /**
     * This (employee, type, year) pot, created from the type's entitlement
     * the first time it is asked for.
     */
    public function find(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        $existing = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => $year,
            'entitlement' => $type->entitlement_days,
            'carry_forward' => 0,
            'adjustment' => 0,
            'used' => 0,
            'pending' => 0,
        ]);
    }

    /**
     * Hold days for a request that is going to an approver.
     *
     * @throws ValidationException when the pot would go negative
     */
    public function reserve(Employee $employee, LeaveType $type, int $year, float $days): LeaveBalance
    {
        return $this->move($employee, $type, $year, function (LeaveBalance $balance) use ($type, $days) {
            if ($days <= 0) {
                return;
            }

            // Ask BEFORE writing, against the locked row: checking a stale
            // copy and then debiting a fresh one is how two requests both
            // pass the balance test.
            if ($balance->wouldGoNegative($days)) {
                throw ValidationException::withMessages([
                    'leave_type_id' => sprintf(
                        'This leave type only has %s day(s) available; you asked for %s.',
                        $this->format($balance->remaining()),
                        $this->format($days),
                    ),
                ]);
            }

            // Unpaid leave has no pot, so there is nothing to reserve
            // against — recording pending days there would make every
            // request fail a check that has no meaning for it.
            if ((bool) $type->is_paid) {
                $balance->pending = round((float) $balance->pending + $days, 2);
            }
        });
    }

    /**
     * An approved request stops being pending and becomes used.
     */
    public function commit(Employee $employee, LeaveType $type, int $year, float $days): LeaveBalance
    {
        return $this->move($employee, $type, $year, function (LeaveBalance $balance) use ($type, $days) {
            if ($days <= 0 || ! (bool) $type->is_paid) {
                return;
            }

            $balance->pending = $this->sub($balance->pending, $days);
            $balance->used = round((float) $balance->used + $days, 2);
        });
    }

    /**
     * A rejected, cancelled or LOP-converted request gives its days back.
     *
     * Idempotent in effect: the pending figure is floored at zero, so a
     * double release (a bug, or a retried job) cannot mint days out of
     * nothing. It also releases `used`, which is what makes LOP conversion
     * correct — see LeaveRequestService::convertToLop().
     */
    public function release(Employee $employee, LeaveType $type, int $year, float $days, bool $releaseUsed = false): LeaveBalance
    {
        return $this->move($employee, $type, $year, function (LeaveBalance $balance) use ($type, $days, $releaseUsed) {
            if ($days <= 0) {
                return;
            }

            if ((bool) $type->is_paid) {
                $balance->pending = $this->sub($balance->pending, $days);

                // Only a paid type ever accrued `used`. Releasing from an
                // unpaid one would take days it never gave.
                if ($releaseUsed) {
                    $balance->used = $this->sub($balance->used, $days);
                }
            }
        });
    }

    /**
     * A manual correction: last year's carry-forward, an HR-approved
     * top-up, or a claw-back.
     *
     * `adjustment` rather than `entitlement`, so the original grant stays
     * visible and the correction is a separate, signed number.
     */
    public function adjust(LeaveBalance $balance, int $entitlement, int $carryForward, int $adjustment): LeaveBalance
    {
        return DB::transaction(function () use ($balance, $entitlement, $carryForward, $adjustment) {
            /** @var LeaveBalance $fresh */
            $fresh = LeaveBalance::query()
                ->whereKey($balance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $fresh->entitlement = max(0, $entitlement);
            $fresh->carry_forward = max(0, $carryForward);
            $fresh->adjustment = $adjustment;
            $fresh->save();

            return $fresh;
        });
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Lock, mutate, save — the shape every movement shares.
     *
     * @param  callable(LeaveBalance): void  $change
     */
    private function move(Employee $employee, LeaveType $type, int $year, callable $change): LeaveBalance
    {
        return DB::transaction(function () use ($employee, $type, $year, $change) {
            // Create-if-missing has to happen inside the lock too: two
            // simultaneous first-ever requests would otherwise both find no
            // row and both insert one, tripping the unique index for one of
            // them at random.
            $balance = $this->find($employee, $type, $year);

            /** @var LeaveBalance $locked */
            $locked = LeaveBalance::query()
                ->whereKey($balance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $change($locked);
            $locked->save();

            return $locked;
        });
    }

    private function sub(float $current, float $days): float
    {
        return round(max(0.0, (float) $current - $days), 2);
    }

    private function format(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
    }
}
