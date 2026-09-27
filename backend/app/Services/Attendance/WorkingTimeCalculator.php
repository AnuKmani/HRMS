<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;

/**
 * All attendance time arithmetic, here and nowhere else.
 *
 * Controllers store what this returns; they never subtract timestamps
 * themselves. That is the whole design: a rule about overtime changed in one
 * method changes it for check-in, check-out, history and the timeline at
 * once, and a test can pin the rule without booting HTTP.
 *
 * Everything works on absolute timestamps, never on clock faces, so an
 * overnight 22:00 -> 06:00 shift needs no special case: the end is simply
 * the next calendar day and the subtraction is ordinary.
 *
 * Conventions, chosen once and documented rather than rediscovered per call:
 *
 *  - `lateMinutes` is arrival minus scheduled start, unrounded. The grace
 *    period only decides whether that gap makes the day *late*; it does not
 *    erase it, because "12 minutes late inside a 15-minute grace" and
 *    "on time" are different facts a supervisor may need later.
 *  - `workingMinutes` is elapsed time less the schedule's break, floored at
 *    zero. Time actually present is what a construction workforce is paid
 *    for; early arrival is not silently discarded, and a five-minute
 *    mistaken check-in/out does not produce minus fifty-five.
 *  - `overtimeMinutes` accrues only past minimum *plus* the configured
 *    threshold, so a shift run to its own length never books overtime.
 */
final class WorkingTimeCalculator
{
    public function atCheckIn(ShiftPlan $plan, Carbon $checkInAt): CheckInMetrics
    {
        $late = $this->lateMinutes($plan, $checkInAt);

        return new CheckInMetrics(
            lateMinutes: $late,
            isLate: $plan->hasSchedule && $late > $plan->graceMinutes,
            scheduledStart: $plan->scheduledStart,
            scheduledEnd: $plan->scheduledEnd,
            shiftId: $plan->shiftId,
        );
    }

    public function atCheckOut(
        ShiftPlan $plan,
        Carbon $checkInAt,
        Carbon $checkOutAt,
    ): CheckOutMetrics {
        $elapsed = $this->minutesBetween($checkInAt, $checkOutAt);

        $break = min($plan->breakMinutes, $elapsed);
        $working = max(0, $elapsed - $plan->breakMinutes);

        $late = $this->lateMinutes($plan, $checkInAt);

        $early = 0;
        if ($plan->hasSchedule && $plan->scheduledEnd !== null && $checkOutAt->lt($plan->scheduledEnd)) {
            $early = $this->minutesBetween($checkOutAt, $plan->scheduledEnd);
        }

        $overtime = 0;
        if ($plan->hasSchedule && $plan->minimumWorkingMinutes > 0) {
            $overtime = max(
                0,
                $working - $plan->minimumWorkingMinutes - $plan->overtimeThresholdMinutes,
            );
        }

        return new CheckOutMetrics(
            elapsedMinutes: $elapsed,
            breakMinutes: $break,
            workingMinutes: $working,
            lateMinutes: $late,
            isLate: $plan->hasSchedule && $late > $plan->graceMinutes,
            earlyDepartureMinutes: $early,
            overtimeMinutes: $overtime,
        );
    }

    private function lateMinutes(ShiftPlan $plan, Carbon $checkInAt): int
    {
        if (! $plan->hasSchedule || $plan->scheduledStart === null) {
            return 0;
        }

        if ($checkInAt->lte($plan->scheduledStart)) {
            return 0;
        }

        return $this->minutesBetween($plan->scheduledStart, $checkInAt);
    }

    /**
     * Whole minutes from $from to $to, never negative, computed from Unix
     * timestamps so DST shifts and Carbon's float diffs cannot round a
     * 59-minute check-out into an hour.
     */
    private function minutesBetween(Carbon $from, Carbon $to): int
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();

        if ($seconds <= 0) {
            return 0;
        }

        return (int) floor($seconds / 60);
    }
}
