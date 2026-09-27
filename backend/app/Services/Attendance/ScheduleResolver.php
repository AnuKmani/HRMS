<?php

namespace App\Services\Attendance;

use App\Models\Shift;
use App\Models\Site;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Turns "which shift applies on this date?" into concrete datetimes.
 *
 * Resolution order, first match wins:
 *
 *   1. the site's own shift row — a night crew is not the day crew;
 *   2. the seeded `working_hours.default` setting — the organisation's
 *      fallback schedule, editable without a deploy;
 *   3. nothing. No schedule, no lateness, no early departure.
 *
 * Grace period, break and overtime threshold come from the same place as the
 * times they apply to: the shift row if there is one, otherwise the seeded
 * `attendance.*` settings. Nothing in this class is a literal time — the
 * nearest thing to a constant is the `0` returned when a value is genuinely
 * unset, which means "no allowance", not "ten minutes".
 *
 * Overnight shifts: `Shift::crosses_midnight` is authoritative, so a
 * 22:00 -> 06:00 shift resolves to a scheduled END on the *next* calendar
 * day and every downstream subtraction works on real timestamps instead of
 * on clock faces.
 */
final class ScheduleResolver
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  Carbon  $attendanceDate  the day the check-in belongs to
     */
    public function planFor(Site $site, Carbon $attendanceDate): ShiftPlan
    {
        // Lazy-loaded: a soft-deleted shift reads as null, which is the same
        // answer as "this site has no shift" and falls through to settings.
        $shift = $site->shift;

        if ($shift !== null && $shift->status === Shift::STATUS_ACTIVE) {
            return $this->fromShift($shift, $attendanceDate);
        }

        return $this->fromSettings($attendanceDate);
    }

    private function fromShift(Shift $shift, Carbon $day): ShiftPlan
    {
        $start = $this->onDay($day, (string) $shift->start_time);
        $end = $this->onDay($day, (string) $shift->end_time);

        $crossesMidnight = $shift->crosses_midnight;

        if ($crossesMidnight) {
            $end = $end->addDay();
        }

        return new ShiftPlan(
            shiftId: $shift->id,
            scheduledStart: $start,
            scheduledEnd: $end,
            graceMinutes: max(0, (int) $shift->grace_period),
            breakMinutes: max(0, (int) $shift->break_duration),
            minimumWorkingMinutes: max(0, (int) round(((float) $shift->minimum_working_hours) * 60)),
            overtimeThresholdMinutes: max(0, (int) round(((float) $shift->overtime_threshold) * 60)),
            crossesMidnight: (bool) $crossesMidnight,
            hasSchedule: true,
        );
    }

    private function fromSettings(Carbon $day): ShiftPlan
    {
        $fallback = $this->settings->get('working_hours.default');

        if (! is_array($fallback)
            || ! isset($fallback['start'], $fallback['end'])
            || ! is_string($fallback['start'])
            || ! is_string($fallback['end'])) {
            return ShiftPlan::none();
        }

        $start = $this->onDay($day, $fallback['start']);
        $end = $this->onDay($day, $fallback['end']);
        $crossesMidnight = $end->lte($start);

        if ($crossesMidnight) {
            $end = $end->addDay();
        }

        $dailyHours = isset($fallback['daily_hours']) ? (float) $fallback['daily_hours'] : 0.0;
        $breakMinutes = isset($fallback['break_minutes']) ? (int) $fallback['break_minutes'] : 0;

        return new ShiftPlan(
            shiftId: null,
            scheduledStart: $start,
            scheduledEnd: $end,
            graceMinutes: max(0, $this->settings->int('attendance.grace_period_minutes', 0)),
            breakMinutes: max(0, $breakMinutes),
            minimumWorkingMinutes: max(0, (int) round($dailyHours * 60)),
            overtimeThresholdMinutes: max(0, $this->settings->int('attendance.overtime_threshold_minutes', 0)),
            crossesMidnight: $crossesMidnight,
            hasSchedule: true,
        );
    }

    /**
     * Place an HH:MM[:SS] clock time on a calendar day.
     *
     * `setTime()` rather than string concatenation, so `09:00` and `9:00`
     * and a driver-written `09:00:00` all land on the same instant.
     */
    private function onDay(Carbon $day, string $clock): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $clock), 2, '0');

        return $day->copy()->setTime((int) $hour, (int) $minute, 0);
    }
}
