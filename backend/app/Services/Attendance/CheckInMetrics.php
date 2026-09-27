<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;

/**
 * What check-in said about the morning.
 *
 * `lateMinutes` is the raw gap between scheduled start and actual arrival —
 * factual, never rounded down to zero. Whether that gap counts as *late* is
 * `isLate`, which applies the grace period. Keeping the two apart is what
 * lets a record say "12 minutes late, inside the 15-minute grace" instead of
 * collapsing the distinction nobody can see afterwards.
 */
final class CheckInMetrics
{
    public function __construct(
        public readonly int $lateMinutes,
        public readonly bool $isLate,
        public readonly ?Carbon $scheduledStart,
        public readonly ?Carbon $scheduledEnd,
        public readonly ?int $shiftId,
    ) {}
}
