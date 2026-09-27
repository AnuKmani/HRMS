<?php

namespace App\Services\Timesheet;

use App\Models\Attendance;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Visibility;
use Illuminate\Validation\ValidationException;

/**
 * Turns attendance into timesheets, and owns the derivation rules.
 *
 * A timesheet here is not a second record of when somebody arrived —
 * `attendances` owns that, immutably, with no override endpoint. What is
 * derived is the *projection*: attendance rows keyed (employee, date) plus
 * the project/site/shift dimensions and a status a period report can filter
 * on, materialised so a month is one indexed read instead of a month of joins
 * re-run on every view.
 *
 * Derivation in full, and nothing else:
 *
 *   check_in_at / check_out_at  copied, so the period shows what was true
 *                               when it was generated
 *   working/break/overtime      copied from attendance, never recomputed
 *   project, site, shift        copied from that day's attendance row
 *   status                      open      — no check-out yet (day runs on)
 *                               incomplete — the day closed with no usable
 *                                            working time
 *                               complete   — otherwise
 *
 * **Idempotent by construction**: the target is `updateOrCreate` over the
 * (employee_id, timesheet_date) unique index, so running `generate` over the
 * same period twice updates rows rather than duplicating them. That is why a
 * manager who is not sure whether somebody else already ran it can press the
 * button safely.
 *
 * Scope is not this class's decision. The caller's visibility is applied
 * through Visibility::attendanceFor() — the same rule attendance uses — so a
 * supervisor generating a period gets their sites and an admin gets
 * everything, without a second scope rule that could disagree with the first.
 */
final class TimesheetService
{
    /**
     * "Generate March" is fine; "generate everything since 2019" is a typo
     * waiting to happen, and one that would write a very large number of rows.
     */
    private const MAX_SPAN_DAYS = 366;

    /**
     * Derive timesheets for every visible attendance row in the range.
     *
     * @return int rows written (created or updated)
     */
    public function generate(User $user, string $from, string $to, ?int $employeeId = null): int
    {
        $fromTime = strtotime($from);
        $toTime = strtotime($to);

        if ($fromTime === false || $toTime === false) {
            throw ValidationException::withMessages([
                'from' => 'Enter a valid date range.',
            ]);
        }

        if ($toTime < $fromTime) {
            throw ValidationException::withMessages([
                'to' => 'The end of the range must be on or after its start.',
            ]);
        }

        if ((int) (($toTime - $fromTime) / 86400) >= self::MAX_SPAN_DAYS) {
            throw ValidationException::withMessages([
                'to' => sprintf('A single generation may not span more than %d days.', self::MAX_SPAN_DAYS),
            ]);
        }

        $query = Visibility::attendanceFor(
            Attendance::query()->whereBetween('attendance_date', [$from, $to]),
            $user,
        );

        // Narrowing to one person is applied *after* the scope, so it can only
        // ever shrink the set — never widen it beyond what the caller sees.
        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        $written = 0;

        foreach ($query->orderBy('attendance_date')->get() as $attendance) {
            $this->upsert($attendance);
            $written++;
        }

        return $written;
    }

    /**
     * One attendance row -> one timesheet row.
     *
     * Public so a single day can be refreshed after an attendance correction
     * without regenerating a whole period.
     */
    public function upsert(Attendance $attendance): Timesheet
    {
        $status = match (true) {
            $attendance->check_out_at === null => Timesheet::STATUS_OPEN,
            $attendance->status === Attendance::STATUS_INCOMPLETE => Timesheet::STATUS_INCOMPLETE,
            $attendance->working_minutes === 0 => Timesheet::STATUS_INCOMPLETE,
            default => Timesheet::STATUS_COMPLETE,
        };

        /** @var Timesheet $sheet */
        $sheet = Timesheet::query()->updateOrCreate(
            [
                'employee_id' => $attendance->employee_id,
                'timesheet_date' => $attendance->attendance_date->toDateString(),
            ],
            [
                'project_id' => $attendance->project_id,
                'site_id' => $attendance->site_id,
                'shift_id' => $attendance->shift_id,
                'attendance_id' => $attendance->id,
                'check_in_at' => $attendance->check_in_at,
                'check_out_at' => $attendance->check_out_at,
                'working_minutes' => $attendance->working_minutes,
                'break_minutes' => $attendance->break_minutes,
                'overtime_minutes' => $attendance->overtime_minutes,
                'status' => $status,
            ],
        );

        return $sheet;
    }
}
