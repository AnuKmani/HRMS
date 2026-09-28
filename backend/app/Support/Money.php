<?php

namespace App\Support;

/**
 * The one place a number becomes money, and money becomes a number.
 *
 * Two rules, both load-bearing:
 *
 *  1. **Rounding happens here or it does not happen.** Every figure that
 *     leaves the calculation is passed through {@see self::round()} exactly
 *     once, at the moment it is materialised - a column, an item row, a PDF
 *     line, a JSON response. Nothing downstream is allowed to re-round, and
 *     nothing upstream is allowed to skip it, so two screens showing the
 *     same net salary cannot disagree in the second decimal place.
 *
 *  2. **PHP's `round()` is the rule: half away from zero, two decimal
 *     places.** Not banker's rounding, not "whatever the format string did".
 *     A payslip that rounds 0.125 one way in a test and another way in a
 *     widget is a payslip nobody trusts.
 *
 * Why arithmetic itself is still performed on floats when the schema is
 * DECIMAL: PHP has no fixed-point type, and adding a bcmath dependency for
 * sums bounded by DECIMAL(12,2) buys nothing a single `round()` at the
 * boundary does not - every value here is far inside the 2^53 range where a
 * double represents a two-decimal figure exactly once rounded. What must
 * *never* happen is a float reaching storage: every money column in this
 * schema is DECIMAL, and {@see self::decimal()} is the only string form
 * anything writes.
 *
 * Formatting (currency codes, thousands separators) is deliberately NOT
 * here: it belongs to the presentation layer, and Flutter owns its own copy
 * in `core/presentation/money.dart`. This class answers "what is the
 * number?", never "how does it look?".
 */
final class Money
{
    /** Places every money figure is rounded to. */
    public const SCALE = 2;

    private function __construct() {}

    /**
     * Round a money figure to the stored precision.
     *
     * Accepts a numeric string because DECIMAL columns come back from MySQL
     * as strings - casting to float at the edge and rounding immediately is
     * the safe order; reading a raw string into arithmetic is not.
     */
    public static function round(int|float|string|null $amount): float
    {
        if ($amount === null || $amount === '') {
            return 0.0;
        }

        return round((float) $amount, self::SCALE);
    }

    /**
     * The string form a DECIMAL column is written with.
     *
     * Always two places, never scientific notation, never an empty string -
     * a value this function returns can be bound into a query without
     * deciding anything about it first.
     */
    public static function decimal(int|float|string|null $amount): string
    {
        return number_format(self::round($amount), self::SCALE, '.', '');
    }

    /**
     * Add a column of figures without letting floating point drift across a
     * long list. Rounding each term as it is folded in means the total of
     * sixty allowances equals the sixty amounts printed on the slip.
     *
     * @param  iterable<int, int|float|string|null>  $amounts
     */
    public static function sum(iterable $amounts): float
    {
        $total = 0.0;

        foreach ($amounts as $amount) {
            $total = self::round($total + self::round($amount));
        }

        return $total;
    }

    /**
     * Percentage of a base, to two places - how a day's pay is derived from
     * a month's. `0` is returned for an unusable divisor rather than INF or
     * NAN, both of which would be written to DECIMAL as something absurd.
     */
    public static function proRate(float $base, float $days, float $divisor): float
    {
        if ($divisor <= 0.0) {
            return 0.0;
        }

        return self::round($base / $divisor * $days);
    }
}
