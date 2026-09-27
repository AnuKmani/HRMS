<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;

/**
 * The schedule in force for one attendance day, resolved once and then read
 * by everything that has an opinion about time.
 *
 * Frozen at check-in: `scheduled_start_at` / `scheduled_end_at` are written
 * to the attendance row from this, so editing a shift for next month cannot
 * retroactively change what last Tuesday's lateness meant.
 *
 * `hasSchedule` false is a legitimate state — no shift on the site and no
 * `working_hours.default` seeded — and means "there is nothing to be late
 * for", never "assume 09:00 to 18:00".
 */
final class ShiftPlan
{
    public function __construct(
        public readonly ?int $shiftId,
        public readonly ?Carbon $scheduledStart,
        public readonly ?Carbon $scheduledEnd,
        public readonly int $graceMinutes,
        public readonly int $breakMinutes,
        public readonly int $minimumWorkingMinutes,
        public readonly int $overtimeThresholdMinutes,
        public readonly bool $crossesMidnight,
        public readonly bool $hasSchedule,
    ) {}

    public static function none(): self
    {
        return new self(
            shiftId: null,
            scheduledStart: null,
            scheduledEnd: null,
            graceMinutes: 0,
            breakMinutes: 0,
            minimumWorkingMinutes: 0,
            overtimeThresholdMinutes: 0,
            crossesMidnight: false,
            hasSchedule: false,
        );
    }

    /**
     * @return array<string, int|null>
     */
    public function toColumns(): array
    {
        return [
            'shift_id' => $this->shiftId,
            'scheduled_start_at' => $this->scheduledStart?->toDateTimeString(),
            'scheduled_end_at' => $this->scheduledEnd?->toDateTimeString(),
        ];
    }
}
