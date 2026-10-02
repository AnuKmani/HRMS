<?php

namespace App\Services\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The six filters every report endpoint accepts, and the rule for what
 * happens when a report does not have one.
 *
 * `from`/`to` are inclusive calendar bounds on whatever date column the
 * definition declares — inclusive because "the 1st to the 30th" is what a
 * person means and half-open ranges are what a database prefers; the
 * conversion lives here so it happens once.
 *
 * **An unsupported filter is a 422, never a silent drop.** The asset
 * register has no site to filter on (an asset is not *at* a site, it is
 * assigned to a person), and quietly ignoring `site_id` would hand back
 * every asset while the URL claimed to be showing the ones at Dubai
 * Yard — a report that lies about what it is showing is worse than one
 * that refuses to run. The catalogue (`GET /reports`) lists each
 * report's `supported_filters` so a client never offers a control the
 * data cannot answer, and a bookmarked URL that outlives its usefulness
 * gets a message naming the filter rather than a wrong table.
 *
 * A value that does not parse as a date is dropped rather than rejected:
 * `from=last-tuesday` is a filter the caller did not mean to apply, and
 * dropping it changes the result set from what was asked, so it is
 * *also* a lie. Unlike an unsupported column, a malformed value has no
 * obvious intent — and a filter nobody is sure of should not silently
 * widen a report, so it 422s too. Same exception, different message.
 */
final class ReportFilters
{
    /**
     * The six, in the order a screen draws them.
     *
     * @var array<int, string>
     */
    public const ALL = ['from', 'to', 'site_id', 'department_id', 'employee_id', 'status'];

    private function __construct() {}

    /**
     * Read the six off the query string, keep only the ones present.
     *
     * @return array<string, string>
     */
    public static function read(Request $request): array
    {
        $filters = [];

        foreach (self::ALL as $name) {
            $value = $request->query($name);

            if (is_scalar($value) && trim((string) $value) !== '') {
                $filters[$name] = trim((string) $value);
            }
        }

        return $filters;
    }

    /**
     * Drop anything the report cannot answer, raising on a filter that is
     * present but unsupported. Returns the surviving set.
     *
     * @param  array<string, string>  $requested
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function narrow(ReportDefinition $definition, array $requested): array
    {
        $unsupported = array_values(array_diff(array_keys($requested), $definition->supportedFilters()));

        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                'filters' => sprintf(
                    'The %s report does not support the %s filter. Supported filters: %s.',
                    $definition->title,
                    implode(', ', $unsupported),
                    implode(', ', $definition->supportedFilters()),
                ),
            ]);
        }

        foreach (['from', 'to'] as $bound) {
            if (isset($requested[$bound]) && ! self::parses($requested[$bound])) {
                throw ValidationException::withMessages([
                    $bound => "The {$bound} filter must be a date, for example 2026-10-01.",
                ]);
            }
        }

        // `to` alone is "everything up to and including that day"; `from`
        // alone is "everything since". Neither needs the other, and a
        // caller who supplies both gets the window they asked for.
        return $requested;
    }

    /**
     * Apply the surviving filters to the report's base query.
     *
     * @param  array<string, string>  $filters
     */
    public static function apply(Builder $query, ReportDefinition $definition, array $filters): void
    {
        foreach ($filters as $name => $value) {
            $column = $definition->filterColumns[$name];

            // A closure means "this filter has no column of its own" —
            // see the asset register's employee filter, which is an
            // EXISTS over hand-overs rather than a join, so an asset
            // issued three times is printed once.
            if ($column instanceof \Closure) {
                $column($query, $value);

                continue;
            }

            match ($name) {
                'from' => $query->whereDate($column, '>=', $value),
                'to' => $query->whereDate($column, '<=', $value),
                default => $query->where($column, $value),
            };
        }
    }

    /**
     * A summary line for a header — "1 Jan to 31 Mar · Site: Dubai Yard".
     * Built from the *applied* filters so a PDF filed six months from now
     * still says what it actually covered.
     *
     * @param  array<string, string>  $filters
     */
    public static function describe(array $filters): string
    {
        if ($filters === []) {
            return 'All records, no filters applied.';
        }

        $labels = [
            'from' => 'From',
            'to' => 'To',
            'site_id' => 'Site',
            'department_id' => 'Department',
            'employee_id' => 'Employee',
            'status' => 'Status',
        ];

        $parts = [];

        foreach ($filters as $name => $value) {
            $parts[] = ($labels[$name] ?? $name).': '.$value;
        }

        return implode(' · ', $parts);
    }

    private static function parses(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
