<?php

namespace Tests\Unit;

use App\Services\Attendance\CheckInMetrics;
use App\Services\Attendance\ShiftPlan;
use App\Services\Attendance\WorkingTimeCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Time arithmetic, tested without booting HTTP — because this is where the
 * rules actually live.
 *
 * Every expectation below is written out in minutes rather than derived, so
 * a change to one convention fails here first and loudly instead of quietly
 * shifting somebody's recorded working day.
 */
class WorkingTimeCalculatorTest extends TestCase
{
    private WorkingTimeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new WorkingTimeCalculator;
    }

    private function dayShift(): ShiftPlan
    {
        return new ShiftPlan(
            shiftId: 7,
            scheduledStart: Carbon::parse('2026-09-27 09:00:00'),
            scheduledEnd: Carbon::parse('2026-09-27 18:00:00'),
            graceMinutes: 15,
            breakMinutes: 60,
            minimumWorkingMinutes: 480,
            overtimeThresholdMinutes: 30,
            crossesMidnight: false,
            hasSchedule: true,
        );
    }

    private function nightShift(): ShiftPlan
    {
        return new ShiftPlan(
            shiftId: 9,
            scheduledStart: Carbon::parse('2026-09-27 22:00:00'),
            scheduledEnd: Carbon::parse('2026-09-28 06:00:00'),
            graceMinutes: 10,
            breakMinutes: 30,
            minimumWorkingMinutes: 450,
            overtimeThresholdMinutes: 30,
            crossesMidnight: true,
            hasSchedule: true,
        );
    }

    /* --------------------------------------------------------- check-in */

    public function test_arriving_on_time_is_never_late(): void
    {
        $metrics = $this->calculator->atCheckIn($this->dayShift(), Carbon::parse('2026-09-27 09:00:00'));

        $this->assertSame(0, $metrics->lateMinutes);
        $this->assertFalse($metrics->isLate);
    }

    public function test_arriving_early_counts_as_zero_not_negative(): void
    {
        $metrics = $this->calculator->atCheckIn($this->dayShift(), Carbon::parse('2026-09-27 08:12:00'));

        $this->assertSame(0, $metrics->lateMinutes);
        $this->assertFalse($metrics->isLate);
    }

    public function test_the_grace_period_decides_lateness_but_does_not_erase_it(): void
    {
        // 12 minutes past the start, inside a 15-minute grace: the record
        // keeps "12 minutes late" as a fact while the day stays `present`.
        $metrics = $this->calculator->atCheckIn($this->dayShift(), Carbon::parse('2026-09-27 09:12:00'));

        $this->assertSame(12, $metrics->lateMinutes);
        $this->assertFalse($metrics->isLate);
    }

    public function test_one_minute_past_the_grace_marks_the_morning_late(): void
    {
        $metrics = $this->calculator->atCheckIn($this->dayShift(), Carbon::parse('2026-09-27 09:16:00'));

        $this->assertSame(16, $metrics->lateMinutes);
        $this->assertTrue($metrics->isLate);
    }

    public function test_the_scheduled_window_is_carried_through_for_the_row(): void
    {
        $metrics = $this->calculator->atCheckIn($this->dayShift(), Carbon::parse('2026-09-27 09:00:00'));

        $this->assertSame('2026-09-27 09:00:00', $metrics->scheduledStart?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-27 18:00:00', $metrics->scheduledEnd?->format('Y-m-d H:i:s'));
        $this->assertSame(7, $metrics->shiftId);
    }

    public function test_no_schedule_means_nothing_can_be_late(): void
    {
        $metrics = $this->calculator->atCheckIn(ShiftPlan::none(), Carbon::parse('2026-09-27 23:59:00'));

        $this->assertSame(0, $metrics->lateMinutes);
        $this->assertFalse($metrics->isLate);
        $this->assertNull($metrics->scheduledStart);
    }

    /* --------------------------------------------------------- check-out */

    public function test_a_full_day_is_the_window_less_the_break(): void
    {
        $metrics = $this->calculator->atCheckOut(
            $this->dayShift(),
            Carbon::parse('2026-09-27 09:00:00'),
            Carbon::parse('2026-09-27 18:00:00'),
        );

        $this->assertSame(540, $metrics->elapsedMinutes);
        $this->assertSame(60, $metrics->breakMinutes);
        $this->assertSame(480, $metrics->workingMinutes);
        $this->assertSame(0, $metrics->overtimeMinutes);
        $this->assertSame(0, $metrics->earlyDepartureMinutes);
    }

    public function test_leaving_early_is_measured_against_the_scheduled_end(): void
    {
        $metrics = $this->calculator->atCheckOut(
            $this->dayShift(),
            Carbon::parse('2026-09-27 09:00:00'),
            Carbon::parse('2026-09-27 16:00:00'),
        );

        $this->assertSame(120, $metrics->earlyDepartureMinutes);
        $this->assertSame(360, $metrics->workingMinutes);
    }

    public function test_staying_past_the_end_is_not_a_negative_early_departure(): void
    {
        $metrics = $this->calculator->atCheckOut(
            $this->dayShift(),
            Carbon::parse('2026-09-27 09:00:00'),
            Carbon::parse('2026-09-27 20:00:00'),
        );

        $this->assertSame(0, $metrics->earlyDepartureMinutes);
    }

    public function test_overtime_accrues_only_past_minimum_plus_the_threshold(): void
    {
        // 19:30 finish -> 630 elapsed - 60 break = 570 working.
        // 570 - 480 minimum - 30 threshold = 60 minutes of overtime.
        $metrics = $this->calculator->atCheckOut(
            $this->dayShift(),
            Carbon::parse('2026-09-27 09:00:00'),
            Carbon::parse('2026-09-27 19:30:00'),
        );

        $this->assertSame(570, $metrics->workingMinutes);
        $this->assertSame(60, $metrics->overtimeMinutes);
    }

    public function test_a_day_run_to_its_own_length_books_no_overtime(): void
    {
        // 18:30 finish -> 570 elapsed - 60 break = 510 working, which is
        // exactly minimum plus threshold. The 15 minutes a full day's
        // punctuality buys must not be mistaken for overtime.
        $metrics = $this->calculator->atCheckOut(
            $this->dayShift(),
            Carbon::parse('2026-09-27 09:00:00'),
            Carbon::parse('2026-09-27 18:30:00'),
        );

        $this->assertSame(510, $metrics->workingMinutes);
        $this->assertSame(0, $metrics->overtimeMinutes);
    }

    public function test_a_five_minute_mistake_does_not_produce_negative_working_time(): void
    {
        $metrics = $this->calculator->atCheckOut(
            $this->dayShift(),
            Carbon::parse('2026-09-27 09:00:00'),
            Carbon::parse('2026-09-27 09:05:00'),
        );

        $this->assertSame(0, $metrics->workingMinutes);
        $this->assertSame(5, $metrics->breakMinutes);
    }

    public function test_the_lateness_carried_to_check_out_matches_the_one_from_check_in(): void
    {
        $checkIn = Carbon::parse('2026-09-27 09:40:00');

        $morning = $this->calculator->atCheckIn($this->dayShift(), $checkIn);
        $evening = $this->calculator->atCheckOut($this->dayShift(), $checkIn, Carbon::parse('2026-09-27 18:00:00'));

        $this->assertSame($morning->lateMinutes, $evening->lateMinutes);
        $this->assertSame($morning->isLate, $evening->isLate);
        $this->assertSame(40, $evening->lateMinutes);
    }

    /* ------------------------------------------------------- overnight */

    public function test_an_overnight_shift_spanning_midnight_is_one_continuous_window(): void
    {
        // 22:00 -> 06:30 is 8h30 across two calendar days. Every figure below
        // would be wrong if the shift were read as two separate clock faces.
        $metrics = $this->calculator->atCheckOut(
            $this->nightShift(),
            Carbon::parse('2026-09-27 22:00:00'),
            Carbon::parse('2026-09-28 06:30:00'),
        );

        $this->assertSame(510, $metrics->elapsedMinutes);
        $this->assertSame(30, $metrics->breakMinutes);
        $this->assertSame(480, $metrics->workingMinutes);
        $this->assertSame(0, $metrics->lateMinutes);
        $this->assertSame(0, $metrics->earlyDepartureMinutes);
        $this->assertSame(0, $metrics->overtimeMinutes);
    }

    public function test_leaving_the_night_shift_at_midnight_is_an_early_departure_not_a_negative(): void
    {
        $metrics = $this->calculator->atCheckOut(
            $this->nightShift(),
            Carbon::parse('2026-09-27 22:00:00'),
            Carbon::parse('2026-09-28 00:00:00'),
        );

        $this->assertSame(120, $metrics->elapsedMinutes);
        $this->assertSame(360, $metrics->earlyDepartureMinutes);
        $this->assertSame(90, $metrics->workingMinutes);
    }

    public function test_arriving_late_to_a_night_shift_counts_from_the_previous_evening(): void
    {
        $metrics = $this->calculator->atCheckIn($this->nightShift(), Carbon::parse('2026-09-27 22:25:00'));

        $this->assertSame(25, $metrics->lateMinutes);
        $this->assertTrue($metrics->isLate);
    }

    /* ------------------------------------------------- defensive reading */

    public function test_a_check_out_before_the_check_in_cannot_travel_backwards(): void
    {
        $metrics = $this->calculator->atCheckOut(
            $this->dayShift(),
            Carbon::parse('2026-09-27 09:00:00'),
            Carbon::parse('2026-09-27 08:00:00'),
        );

        $this->assertSame(0, $metrics->elapsedMinutes);
        $this->assertSame(0, $metrics->workingMinutes);
    }

    public function test_check_in_metrics_are_a_value_object_that_cannot_drift(): void
    {
        $metrics = $this->calculator->atCheckIn($this->dayShift(), Carbon::parse('2026-09-27 09:03:00'));

        $this->assertInstanceOf(CheckInMetrics::class, $metrics);
        $this->assertSame(3, $metrics->lateMinutes);
    }
}
