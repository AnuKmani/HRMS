<?php

namespace App\Services\Attendance;

use App\Models\Attendance;

/**
 * The only place an attendance status is chosen.
 *
 * Five values, one rule table, one method per decision — no controller and
 * no service ever writes `status` directly. That is what stops
 * `missing_checkout` being computed one way in the list and another way in
 * `today`, which is the kind of disagreement that surfaces as a payroll
 * question months later.
 *
 * Priority at check-out, decided deliberately:
 *
 *   incomplete  — the day did not reach its minimum. More actionable than
 *                 lateness, and `late_minutes` still carries the arrival
 *                 gap, so nothing is lost by not being the headline.
 *   late        — a full day that started past the grace period.
 *   present     — otherwise.
 *
 * `manually_adjusted` is reserved: nothing in Phase 5 writes it, because the
 * override UI is deferred. The value exists so a future override changes a
 * status the schema already understands rather than adding a sixth string.
 */
final class AttendanceStatusCalculator
{
    /**
     * Open attendance — decided by arrival alone, because nothing else is
     * known yet.
     */
    public function atCheckIn(CheckInMetrics $metrics): string
    {
        return $metrics->isLate
            ? Attendance::STATUS_LATE
            : Attendance::STATUS_PRESENT;
    }

    /**
     * Closed attendance — the whole day is on the table.
     */
    public function atCheckOut(
        ShiftPlan $plan,
        CheckInMetrics $checkIn,
        CheckOutMetrics $checkOut,
    ): string {
        if ($plan->hasSchedule
            && $plan->minimumWorkingMinutes > 0
            && $checkOut->workingMinutes < $plan->minimumWorkingMinutes) {
            return Attendance::STATUS_INCOMPLETE;
        }

        return $checkIn->isLate || $checkOut->isLate
            ? Attendance::STATUS_LATE
            : Attendance::STATUS_PRESENT;
    }

    /**
     * An open attendance whose scheduled end has passed.
     *
     * Returned by AttendanceService::flagMissingCheckouts() and nowhere else,
     * so "somebody went home without signing out" is one string in one place.
     */
    public function missingCheckout(): string
    {
        return Attendance::STATUS_MISSING_CHECKOUT;
    }
}
