<?php

namespace App\Services\Leave;

use App\Models\Holiday;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The one place a date range turns into a number of leave days.
 *
 * Nothing else in the codebase is allowed to do this arithmetic. A controller
 * that subtracted dates itself, a job that counted holidays its own way and a
 * Flutter screen that guessed at weekends would produce three answers, and
 * whichever one the balance was debited by would be the one that mattered.
 *
 * What a day counts as:
 *
 *   - **working days** come from the `working_hours.default` setting, which
 *     carries `["monday", …, "friday"]`. A site with different days points at
 *     its own `working_hours` row; that resolution already exists in
 *     ScheduleResolver and is deliberately NOT re-implemented here — leave is
 *     an organisation-level entitlement, and an employee's own roster changes
 *     week to week.
 *   - **weekends** are simply "not in working_days", so a Sunday-first
 *     operation gets the right answer without a second rule.
 *   - **holidays** are active rows on `holidays` whose date falls in the
 *     range and which are either unscoped (`site_id` null — public and
 *     company) or scoped to the request's site. One query, one map.
 *   - **partial dates are not supported.** A range is inclusive of both its
 *     ends and counts whole dates. `requested_days` is DECIMAL(6,2) so half
 *     days can be introduced later, but nothing produces one today — an
 *     unsupported rule that half-works is worse than an absent one.
 *
 * The result is a breakdown rather than a single number so that a rejection
 * ("3 of those 5 days were holidays") can be explained honestly instead of
 * showing a figure nobody can account for.
 */
final class LeaveDayCalculator
{
    /** Refuse a range longer than this — a typo'd year is not a leave request. */
    private const MAX_SPAN_DAYS = 366;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{
     *     start: string,
     *     end: string,
     *     calendar_days: int,
     *     working_days: int,
     *     weekend_days: int,
     *     holiday_days: int,
     *     holiday_dates: array<int, string>
     * }
     */
    public function breakdown(string $start, string $end, ?int $siteId = null): array
    {
        $from = $this->date($start, 'start_date');
        $to = $this->date($end, 'end_date');

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date must be on or after the start date.',
            ]);
        }

        if ($from->diffInDays($to) >= self::MAX_SPAN_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => sprintf(
                    'A single request may not span more than %d days.',
                    self::MAX_SPAN_DAYS,
                ),
            ]);
        }

        $working = $this->workingDayNames();
        $holidays = $this->holidayDates($from, $to, $siteId);

        $calendarDays = 0;
        $workingDays = 0;
        $weekendDays = 0;
        $holidayDays = 0;
        $holidayDates = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $calendarDays++;
            $iso = $date->toDateString();
            $isConfiguredWorkingDay = in_array(strtolower($date->format('l')), $working, true);

            if (isset($holidays[$iso])) {
                // Holiday wins over everything else, in *both* directions.
                //
                // A public holiday that lands on a Saturday cost nobody a
                // working day, so it is not one — counting it as such would
                // inflate the figure the balance is debited by. And a holiday
                // on a Tuesday has to be *removed* from `working_days`, which
                // is the entire reason a company maintains a calendar: the
                // subtraction is the point, not a detail of it.
                if ($isConfiguredWorkingDay) {
                    $holidayDays++;
                    $holidayDates[] = $iso;
                } else {
                    $weekendDays++;
                }

                continue;
            }

            if ($isConfiguredWorkingDay) {
                $workingDays++;
            } else {
                $weekendDays++;
            }
        }

        return [
            'start' => $from->toDateString(),
            'end' => $to->toDateString(),
            'calendar_days' => $calendarDays,
            'working_days' => $workingDays,
            'weekend_days' => $weekendDays,
            'holiday_days' => $holidayDays,
            'holiday_dates' => $holidayDates,
        ];
    }

    /**
     * The number a leave request is recorded and debited with.
     */
    public function count(string $start, string $end, ?int $siteId = null): float
    {
        return (float) $this->breakdown($start, $end, $siteId)['working_days'];
    }

    /**
     * Is this one date a working day for the organisation?
     *
     * Exposed because the timesheet generator and the holiday screen both ask
     * the same question, and both should get the same answer.
     */
    public function isWorkingDay(string $date, ?int $siteId = null): bool
    {
        $day = $this->date($date, 'date');

        if (isset($this->holidayDates($day, $day, $siteId)[$day->toDateString()])) {
            return false;
        }

        return in_array(strtolower($day->format('l')), $this->workingDayNames(), true);
    }

    /**
     * @return array<int, string> lowercase English weekday names
     */
    public function workingDayNames(): array
    {
        $configured = $this->settings->json('working_hours.default')['working_days'] ?? [];

        $names = [];

        foreach ($configured as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = strtolower($name);
            }
        }

        // A setting emptied by a half-finished edit must not silently make
        // every day a weekend (or every day countable). The organisation's
        // default week answers instead.
        return $names === [] ? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] : $names;
    }

    /**
     * Active holidays in the range that could apply — unscoped ones, plus
     * this site's.
     *
     * @return array<string, string> date => date
     */
    private function holidayDates(CarbonImmutable $from, CarbonImmutable $to, ?int $siteId): array
    {
        $rows = Holiday::query()
            ->active()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query) use ($siteId) {
                $query->whereNull('site_id');

                if ($siteId !== null) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->get(['date']);

        $map = [];

        foreach ($rows as $row) {
            $map[$row->date->toDateString()] = true;
        }

        return $map;
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            // Reachable when a caller bypasses the request rules (the
            // scheduler, a seeder, an internal call). Reported against the
            // field rather than as a 500, because "that is not a date" is a
            // message a person can act on.
            throw ValidationException::withMessages([
                $field => 'Enter a valid date.',
            ]);
        }
    }
}
