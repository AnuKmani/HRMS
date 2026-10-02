<?php

namespace App\Services\Reporting;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * One report: what it is called, what it may show, and how to build it.
 *
 * The whole catalogue is data rather than three methods per report, for
 * the reason that made `NotificationServiceProvider` one file too: a
 * report is a *permission*, a *date column*, a *status column* and a
 * handful of columns to print. Encoding that as fourteen classes means
 * fourteen places where "which permission gates this?" could be answered
 * differently, and the fourteenth is the one that leaks a salary column
 * to a Project Manager.
 *
 * `permission` is the per-report gate and it is deliberately **not**
 * `reports.view`. That middleware on the route answers "may this account
 * open the reporting screen at all?"; this answers "may this account run
 * *this* report?". A salary register behind `reports.view` would be
 * reachable by everybody who can see a attendance list, which is not a
 * report, it is a payroll disclosure with extra steps. ReportService
 * re-checks it after the key has already been read from the path, so a
 * typed URL can never reach a report its role does not hold.
 *
 * `filterColumns` maps the six API filter names onto a column this
 * report's query actually has. `null` means "not supported" — see
 * ReportFilters for why an unsupported filter is a 422 rather than a
 * silently ignored line.
 */
final class ReportDefinition
{
    /**
     * @param  array<int, array{key: string, label: string, type: string}>  $columns
     *                                                                                `text` `date` `datetime` `number` `money` `minutes`
     * @param  array<string, string|(Closure(Builder, string): mixed)|null>  $filterColumns
     *                                                                                       filter name => qualified column, a custom
     *                                                                                       constraint for filters no column can
     *                                                                                       answer, or null when unsupported
     * @param  Closure(): Builder  $builder  returns the base query, joins and all
     * @param  string  $orderBy  ORDER BY fragment an export pages on — a constant,
     *                           never user input, and unique (or grouped) so two
     *                           rows sharing a date cannot swap places between
     *                           page 1 and page 2 and be printed twice
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly string $permission,
        public readonly array $columns,
        public readonly array $filterColumns,
        public readonly Closure $builder,
        public readonly string $orderBy,
    ) {}

    /**
     * Column headings, in print order — derived here rather than listed a
     * second time in the view, so adding a column to a report adds it to
     * the CSV, the XLSX and the PDF in one edit.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_column($this->columns, 'label');
    }

    /**
     * Keys, in the same order, for reading a row back out.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_column($this->columns, 'key');
    }

    public function supports(string $filter): bool
    {
        return ($this->filterColumns[$filter] ?? null) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function supportedFilters(): array
    {
        return array_values(array_filter(
            ReportFilters::ALL,
            fn (string $filter): bool => $this->supports($filter),
        ));
    }
}
