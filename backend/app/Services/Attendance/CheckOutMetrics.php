<?php

namespace App\Services\Attendance;

/**
 * Everything check-out works out, in one value object so no controller ever
 * does arithmetic on a timestamp itself.
 */
final class CheckOutMetrics
{
    public function __construct(
        /** Elapsed check-in to check-out, minutes. */
        public readonly int $elapsedMinutes,
        /** Portion of [elapsedMinutes] charged as break. */
        public readonly int $breakMinutes,
        /** elapsed minus break, floored at zero. */
        public readonly int $workingMinutes,
        /** Arrival gap, recomputed so both ends of the row agree. */
        public readonly int $lateMinutes,
        public readonly bool $isLate,
        /** Whole minutes short of the scheduled end. */
        public readonly int $earlyDepartureMinutes,
        public readonly int $overtimeMinutes,
    ) {}
}
