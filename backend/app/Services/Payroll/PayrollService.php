<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The only code that writes a payroll row.
 *
 * It does five things and none of them is arithmetic - the sums belong to
 * {@see PayrollCalculationService} and the installment balance to
 * {@see LoanService}, so this class is left with the parts that genuinely
 * need a transaction around them:
 *
 *  1. pick the employees in the run;
 *  2. claim or release loan installments so a re-run cannot double-charge;
 *  3. write the row and replace its items, atomically;
 *  4. refuse to touch a row that has been reviewed, processed or locked;
 *  5. answer the totals question for the summary endpoint.
 *
 * ## Who is in a run
 *
 * **Every employee row that is not soft-deleted**, optionally narrowed by
 * an explicit id list. Status is deliberately not a filter: somebody who
 * resigned on the 20th is owed pay for twenty days, and excluding them
 * because of a dropdown value would be a silent omission in the one place
 * omissions cost money. The honest way to say "this person is not paid" is
 * `employees.salary = null`, which produces a visible `draft` row with a
 * stated reason rather than no row at all.
 *
 * Rows for employees who have left eligibility after being calculated are
 * **kept**, never deleted. A run narrows the set of people it touches; it
 * does not reach out and remove a record somebody may already have read.
 *
 * ## Recalculation
 *
 * Allowed from `draft` and `calculated` only. From `reviewed` onwards the
 * row is the record of a decision, and silently recomputing it would destroy
 * exactly what the review was of - so the refusal is a 409 naming the state,
 * not a 403 that makes it sound like a permission problem.
 */
final class PayrollService
{
    public function __construct(
        private readonly PayrollCalculationService $calculator,
        private readonly LoanService $loans,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Calculate a whole month.
     *
     * @param  array<int, int>|null  $employeeIds  restrict the run to these people
     * @return array<string, int|string> a run report, never the figures themselves
     */
    public function process(User $actor, int $year, int $month, ?array $employeeIds = null): array
    {
        $bounds = PayrollPeriod::bounds($year, $month);

        return DB::transaction(function () use ($year, $month, $employeeIds, $bounds) {
            // A loan whose start date falls inside this period opens here, in
            // the same transaction as the deduction it is about to produce.
            $this->loans->activateDue($bounds['end']);

            $employees = Employee::query()
                ->when($employeeIds !== null && $employeeIds !== [], fn ($query) => $query->whereIn('id', $employeeIds))
                ->orderBy('id')
                ->get();

            $report = [
                'year' => $year,
                'month' => $month,
                'period_start' => $bounds['start'],
                'period_end' => $bounds['end'],
                'created' => 0,
                'updated' => 0,
                'calculated' => 0,
                'draft' => 0,
                'skipped' => 0,
            ];

            foreach ($employees as $employee) {
                $existing = Payroll::query()
                    ->where('employee_id', $employee->getKey())
                    ->where('payroll_year', $year)
                    ->where('payroll_month', $month)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && $existing->isImmutable()) {
                    // Reviewed, processed or locked. Left exactly as it is -
                    // a run that quietly restated a locked month would be
                    // the bug the lock exists to prevent.
                    $report['skipped']++;

                    continue;
                }

                // Release BEFORE calculating: the calculation reads `pending`
                // installments, and the ones this row holds are still marked
                // `deducted` until they are given back.
                if ($existing !== null) {
                    $this->loans->releaseInstallments($existing);
                }

                $calculation = $this->calculator->calculate(
                    $employee,
                    $year,
                    $month,
                    $bounds['start'],
                    $bounds['end'],
                );

                $payroll = $this->persist($employee, $year, $month, $bounds, $calculation, $existing);
                $this->replaceItems($payroll, $calculation->items);

                foreach ($calculation->installments as $installment) {
                    $this->loans->claimInstallment($installment, $payroll);
                }

                if ($existing !== null) {
                    $report['updated']++;
                } else {
                    $report['created']++;
                }

                if ($calculation->blockedReason !== null) {
                    $report['draft']++;
                } else {
                    $report['calculated']++;
                }
            }

            return $report;
        });
    }

    /**
     * Re-run one row's calculation.
     *
     * The same sequence as a fresh run on an existing row - release, then
     * calculate, then write - because "recalculate" and "process the month
     * again" must be the same operation with a different scope, or the two
     * would eventually disagree about what a row contains.
     */
    public function recalculate(Payroll $payroll): Payroll
    {
        if ($payroll->isImmutable()) {
            abort(
                409,
                'This payroll has already been '.$payroll->status.' and can no longer be recalculated. '
                .'Its figures are fixed; corrections belong in a new allowance, bonus or deduction for the next run.',
            );
        }

        return DB::transaction(function () use ($payroll) {
            $this->loans->releaseInstallments($payroll);

            $employee = $payroll->employee()->lockForUpdate()->firstOrFail();
            $bounds = [
                'start' => $payroll->period_start->toDateString(),
                'end' => $payroll->period_end->toDateString(),
            ];

            $calculation = $this->calculator->calculate(
                $employee,
                (int) $payroll->payroll_year,
                (int) $payroll->payroll_month,
                $bounds['start'],
                $bounds['end'],
            );

            $payroll->fill($calculation->toColumns());
            $payroll->status = $calculation->blockedReason !== null
                ? Payroll::STATUS_DRAFT
                : Payroll::STATUS_CALCULATED;
            $payroll->save();

            $this->replaceItems($payroll, $calculation->items);

            foreach ($calculation->installments as $installment) {
                $this->loans->claimInstallment($installment, $payroll);
            }

            return $payroll->load('items');
        });
    }

    public function review(Payroll $payroll, User $actor): Payroll
    {
        if ($payroll->status !== Payroll::STATUS_CALCULATED) {
            abort(409, 'Only a calculated payroll row can be reviewed. This one is '.$payroll->status.'.');
        }

        $payroll->status = Payroll::STATUS_REVIEWED;
        $payroll->reviewed_at = now();
        $payroll->reviewed_by = $actor->id;
        $payroll->save();

        return $payroll;
    }

    public function finalize(Payroll $payroll, User $actor): Payroll
    {
        // `calculated` is deliberately refused. The ladder is one step at a
        // time - reviewed *then* processed - because `reviewed` is the only
        // status anybody has looked at the figures yet, and letting a run go
        // straight from freshly calculated to processed would make the
        // review step a suggestion rather than a stage.
        if ($payroll->status !== Payroll::STATUS_REVIEWED) {
            abort(409, 'Only a reviewed payroll row can be processed. This one is '.$payroll->status.'.');
        }

        $payroll->status = Payroll::STATUS_PROCESSED;
        $payroll->processed_at = now();
        $payroll->processed_by = $actor->id;
        $payroll->save();

        return $payroll;
    }

    public function lock(Payroll $payroll, User $actor): Payroll
    {
        // And `reviewed` is refused here for the same reason: locking is the
        // statement that this month has been *paid*, which `processed` is
        // the status for. Two paths into `locked` would mean the timestamps
        // answered two different questions with one column.
        if ($payroll->status !== Payroll::STATUS_PROCESSED) {
            abort(409, 'Only a processed payroll row can be locked. This one is '.$payroll->status.'.');
        }

        $payroll->status = Payroll::STATUS_LOCKED;
        $payroll->locked_at = now();
        $payroll->locked_by = $actor->id;
        $payroll->save();

        return $payroll;
    }

    /**
     * Company totals for one period - counts and sums, and no names.
     *
     * Returns aggregates only by construction: there is no `->get()` of rows
     * in here, so a caller holding `payroll.summary.view` but nothing else
     * physically cannot be handed an employee-level figure. That is the
     * point of making the endpoint's shape different from the list's rather
     * than filtering the list differently.
     *
     * @return array<string, int|float|string>
     */
    public function summary(int $year, ?int $month = null, ?int $departmentId = null): array
    {
        $row = Payroll::query()
            ->where('payroll_year', $year)
            ->when($month !== null, fn ($query) => $query->where('payroll_month', $month))
            ->when(
                $departmentId !== null,
                fn ($query) => $query->whereHas(
                    'employee',
                    fn ($employee) => $employee->where('department_id', $departmentId),
                ),
            )
            ->selectRaw(
                // `rows` is a reserved word in MariaDB 10.4 and would need
                // backticks at every reference - an alias nobody chose for a
                // reason is an alias waiting to break on an upgrade, so it is
                // named for what it holds instead.
                'count(*) as row_count,'
                .'coalesce(sum(basic_salary), 0) as basic,'
                .'coalesce(sum(total_allowances), 0) as allowances,'
                .'coalesce(sum(overtime_amount), 0) as overtime,'
                .'coalesce(sum(bonus_amount), 0) as bonuses,'
                .'coalesce(sum(gross_salary), 0) as gross,'
                .'coalesce(sum(lop_amount), 0) as lop,'
                .'coalesce(sum(loan_deduction), 0) as loans,'
                .'coalesce(sum(advance_deduction), 0) as advances,'
                .'coalesce(sum(other_deductions), 0) as other,'
                .'coalesce(sum(total_deductions), 0) as deductions,'
                .'coalesce(sum(net_salary), 0) as net,'
                .'coalesce(sum(lop_days), 0) as lost_days,'
                .'coalesce(sum(overtime_minutes), 0) as overtime_minutes'
            )
            ->first();

        return [
            'year' => $year,
            'month' => $month,
            'employee_count' => (int) ($row->row_count ?? 0),
            'basic_total' => Money::round($row->basic ?? 0),
            'total_allowances' => Money::round($row->allowances ?? 0),
            'overtime_total' => Money::round($row->overtime ?? 0),
            'bonus_total' => Money::round($row->bonuses ?? 0),
            'gross_payroll' => Money::round($row->gross ?? 0),
            'lop_total' => Money::round($row->lop ?? 0),
            'loan_total' => Money::round($row->loans ?? 0),
            'advance_total' => Money::round($row->advances ?? 0),
            'other_deductions_total' => Money::round($row->other ?? 0),
            'total_deductions' => Money::round($row->deductions ?? 0),
            'net_payroll' => Money::round($row->net ?? 0),
            'lost_days' => Money::round($row->lost_days ?? 0),
            'overtime_minutes' => (int) ($row->overtime_minutes ?? 0),
            'currency' => $this->settings->string('system.currency', 'INR'),
        ];
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Create or update the row itself, and choose its status.
     */
    private function persist(
        Employee $employee,
        int $year,
        int $month,
        array $bounds,
        PayrollCalculation $calculation,
        ?Payroll $existing,
    ): Payroll {
        if (! $calculation->balances()) {
            // Not a validation error and not a 409: the figures disagree with
            // their own lines, which can only be a defect in this class.
            abort(500, 'Payroll totals did not balance; nothing was written.');
        }

        $status = $calculation->blockedReason !== null
            ? Payroll::STATUS_DRAFT
            : Payroll::STATUS_CALCULATED;

        $payroll = $existing ?? new Payroll([
            'employee_id' => $employee->getKey(),
            'payroll_year' => $year,
            'payroll_month' => $month,
            'period_start' => $bounds['start'],
            'period_end' => $bounds['end'],
        ]);

        $payroll->fill($calculation->toColumns());
        $payroll->status = $status;
        $payroll->save();

        return $payroll;
    }

    /**
     * Replace the itemisation wholesale.
     *
     * Delete-then-insert rather than a diff: a line whose source row has
     * been deleted or whose rate has changed has no correct *update*, and
     * keeping a stale line alive would break the one invariant the row
     * depends on - that the items add up to the totals beside them.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceItems(Payroll $payroll, array $items): void
    {
        PayrollItem::query()->where('payroll_id', $payroll->getKey())->delete();

        foreach ($items as $item) {
            $payroll->items()->create($item);
        }
    }

    /**
     * The columns a run report is allowed to contain - named so a controller
     * cannot accidentally return the calculations themselves.
     *
     * @return array<int, string>
     */
    public static function reportKeys(): array
    {
        return ['year', 'month', 'period_start', 'period_end', 'created', 'updated', 'calculated', 'draft', 'skipped'];
    }
}
