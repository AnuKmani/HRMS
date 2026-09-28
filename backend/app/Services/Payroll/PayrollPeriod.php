<?php

namespace App\Services\Payroll;

use Carbon\CarbonImmutable;

/**
 * The bounds of one monthly pay period, decided once.
 *
 * Two callers need the same answer - the request rules (so a bad month is a
 * 422 rather than a 500) and the calculation (so the window a figure was
 * produced under is the window the row records). Splitting the arithmetic
 * between them would be the first way for a leap year to disagree with
 * itself.
 */
final class PayrollPeriod
{
    private function __construct() {}

    /**
     * @return array{start: string, end: string} both as `Y-m-d`
     */
    public static function bounds(int $year, int $month): array
    {
        $from = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();

        return [
            'start' => $from->toDateString(),
            'end' => $from->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Is this a month the system will process?
     *
     * The window is deliberately finite. A payroll run for the year 12 or
     * for March 1970 is not a legitimate back-dated correction - it is a
     * typo - and the damage it would do (rows for a period nobody can find
     * in a filtered list) is not proportional to the convenience.
     *
     * @return array<string, string> validation errors, empty when valid
     */
    public static function errors(int $year, int $month): array
    {
        $problems = [];

        if ($month < 1 || $month > 12) {
            $problems['month'] = 'The month must be between 1 and 12.';
        }

        $first = (int) now()->year - 5;
        $last = (int) now()->year + 1;

        if ($year < $first || $year > $last) {
            $problems['year'] = "The year must be between {$first} and {$last}.";
        }

        return $problems;
    }

    /**
     * The month either side of this one, for "previous period" links without
     * a second date library in the client.
     *
     * @return array{year: int, month: int}
     */
    public static function previous(int $year, int $month): array
    {
        return $month === 1
            ? ['year' => $year - 1, 'month' => 12]
            : ['year' => $year, 'month' => $month - 1];
    }
}
