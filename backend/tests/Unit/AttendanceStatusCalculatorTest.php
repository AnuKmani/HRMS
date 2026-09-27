<?php

namespace Tests\Unit;

use App\Models\Attendance;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Services\Attendance\CheckInMetrics;
use App\Services\Attendance\CheckOutMetrics;
use App\Services\Attendance\ShiftPlan;
use PHPUnit\Framework\TestCase;

/**
 * The status rule table, pinned.
 *
 * These five strings end up in payroll reports, so the priority between them
 * is asserted rather than implied: an incomplete day outranks a late one,
 * lateness survives an otherwise full day, and an open attendance is judged
 * on arrival alone because nothing else about it is known yet.
 */
class AttendanceStatusCalculatorTest extends TestCase
{
    private AttendanceStatusCalculator $statuses;

    private ShiftPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statuses = new AttendanceStatusCalculator;

        $this->plan = new ShiftPlan(
            shiftId: 1,
            scheduledStart: null,
            scheduledEnd: null,
            graceMinutes: 15,
            breakMinutes: 60,
            minimumWorkingMinutes: 480,
            overtimeThresholdMinutes: 30,
            crossesMidnight: false,
            hasSchedule: true,
        );
    }

    private function checkIn(int $lateMinutes, bool $isLate): CheckInMetrics
    {
        return new CheckInMetrics($lateMinutes, $isLate, null, null, 1);
    }

    private function checkOut(int $workingMinutes, bool $isLate): CheckOutMetrics
    {
        return new CheckOutMetrics(
            elapsedMinutes: $workingMinutes + 60,
            breakMinutes: 60,
            workingMinutes: $workingMinutes,
            lateMinutes: $isLate ? 30 : 0,
            isLate: $isLate,
            earlyDepartureMinutes: 0,
            overtimeMinutes: 0,
        );
    }

    public function test_an_open_attendance_that_started_on_time_is_present(): void
    {
        $this->assertSame(
            Attendance::STATUS_PRESENT,
            $this->statuses->atCheckIn($this->checkIn(0, false)),
        );
    }

    public function test_an_open_attendance_that_started_past_the_grace_is_late(): void
    {
        $this->assertSame(
            Attendance::STATUS_LATE,
            $this->statuses->atCheckIn($this->checkIn(40, true)),
        );
    }

    public function test_a_short_day_is_incomplete_even_when_it_also_started_late(): void
    {
        // Lateness is not lost — `late_minutes` still carries it — but the
        // headline has to be the thing that needs action.
        $status = $this->statuses->atCheckOut($this->plan, $this->checkIn(40, true), $this->checkOut(300, true));

        $this->assertSame(Attendance::STATUS_INCOMPLETE, $status);
    }

    public function test_a_full_day_that_started_late_stays_late(): void
    {
        $status = $this->statuses->atCheckOut($this->plan, $this->checkIn(40, true), $this->checkOut(480, true));

        $this->assertSame(Attendance::STATUS_LATE, $status);
    }

    public function test_a_full_punctuous_day_is_present(): void
    {
        $status = $this->statuses->atCheckOut($this->plan, $this->checkIn(0, false), $this->checkOut(480, false));

        $this->assertSame(Attendance::STATUS_PRESENT, $status);
    }

    public function test_without_a_schedule_a_short_day_is_not_incomplete(): void
    {
        // No schedule means no minimum, so "too short" is not a thing that
        // can be said about the day.
        $plan = new ShiftPlan(null, null, null, 0, 0, 0, 0, false, false);

        $status = $this->statuses->atCheckOut($plan, $this->checkIn(0, false), $this->checkOut(45, false));

        $this->assertSame(Attendance::STATUS_PRESENT, $status);
    }

    public function test_the_missing_checkout_status_is_a_single_constant(): void
    {
        $this->assertSame(Attendance::STATUS_MISSING_CHECKOUT, $this->statuses->missingCheckout());
    }

    public function test_every_status_the_module_can_produce_is_in_the_vocabulary(): void
    {
        $produced = [
            $this->statuses->atCheckIn($this->checkIn(0, false)),
            $this->statuses->atCheckIn($this->checkIn(40, true)),
            $this->statuses->atCheckOut($this->plan, $this->checkIn(0, false), $this->checkOut(300, false)),
            $this->statuses->missingCheckout(),
        ];

        foreach ($produced as $status) {
            $this->assertContains($status, Attendance::STATUSES);
        }
    }
}
