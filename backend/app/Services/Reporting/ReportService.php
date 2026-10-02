<?php

namespace App\Services\Reporting;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Validation\ValidationException;

/**
 * Runs a report: resolves the key, re-checks the permission, applies the
 * filters, and returns either a page or the whole thing in chunks.
 *
 * The permission is checked **after** the key is read from the path and
 * before the query is built — `reports.view` on the route only proves the
 * account may open the reporting screen, and the per-report permission in
 * `ReportRegistry` is what keeps a salary register out of reach of a role
 * that holds nothing but `attendance.view`. Two gates, deliberately: the
 * route gate is coarse enough to rate-limit, the registry gate is fine
 * enough to be right, and neither can be reached by skipping the other.
 *
 * Every query here is built twice — once to count, once to page — from
 * the same definition, so a total and the rows under it can never come
 * from different filters. The count is taken *before* the ORDER BY is
 * added, because `getCountForPagination()` has to drop ordering anyway
 * and a grouped query would otherwise be wrapped with an order it does
 * not need.
 */
class ReportService
{
    /**
     * Resolve a key to its definition for this user, or refuse.
     *
     * `abort()` rather than a returned null: an unknown report key and a
     * report you may not run must not look different from outside —
     * "there is no such report" and "you may not see that report" give an
     * attacker the same two answers either way, and the second message
     * would confirm a report exists that the caller's role should not
     * even know about.
     */
    public function definition(string $key, User $user): ReportDefinition
    {
        $definition = ReportRegistry::get($key);

        if ($definition === null) {
            abort(404, 'Report not found.');
        }

        if (! $user->can($definition->permission)) {
            abort(403, 'You do not have permission to run this report.');
        }

        return $definition;
    }

    /**
     * Narrow a raw query-string filter set to the ones this report can
     * answer, raising on anything it cannot.
     *
     * @param  array<string, string>  $requested
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function filters(ReportDefinition $definition, array $requested): array
    {
        return ReportFilters::narrow($definition, $requested);
    }

    /**
     * How many rows the filtered report holds — the number that decides
     * whether an export streams now or goes to the queue.
     *
     * @param  array<string, string>  $filters
     */
    public function count(ReportDefinition $definition, array $filters): int
    {
        return (int) $this->query($definition, $filters)->getCountForPagination();
    }

    /**
     * One page of rows as plain arrays, so a model's hidden columns, casts
     * and appended accessors can never reach a report that did not ask for
     * them. `get_object_vars` on the base query's row is the narrowest
     * possible answer: exactly the columns the SELECT named.
     *
     * @param  array<string, string>  $filters
     */
    public function rows(ReportDefinition $definition, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->query($definition, $filters);

        $total = (int) $query->getCountForPagination();

        $items = (clone $query)
            ->orderByRaw($definition->orderBy)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row): array => get_object_vars($row))
            ->all();

        return new Paginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Walk every row in stable pages, calling `$callback` with each slice.
     *
     * Paging rather than `->get()` is the difference between an export of
     * 40,000 rows and a memory limit: one array of forty thousand
     * associative rows is tens of megabytes before PHP's own bookkeeping,
     * and the queue worker building four files in a row should not be the
     * thing that takes the box down. The ORDER BY is a constant from the
     * definition — never a user-supplied column — so a slice boundary
     * cannot shift between calls and repeat or skip a row.
     *
     * @param  array<string, string>  $filters
     * @param  callable(array<int, array<string, mixed>>): void  $callback
     */
    public function chunk(
        ReportDefinition $definition,
        array $filters,
        int $size,
        callable $callback,
        ?int $limit = null,
    ): int {
        $query = $this->query($definition, $filters);
        $total = 0;
        $offset = 0;

        do {
            $rows = (clone $query)
                ->orderByRaw($definition->orderBy)
                ->limit($size)
                ->offset($offset)
                ->get();

            $count = count($rows);

            if ($count > 0) {
                $total += $count;
                $callback($rows->map(fn ($row): array => get_object_vars($row))->all());
            }

            $offset += $size;

            if ($limit !== null && $total >= $limit) {
                break;
            }
        } while ($count === $size);

        return $total;
    }

    /**
     * The filtered query as a plain query builder, scopes applied.
     *
     * `applyScopes()` first: `getQuery()` on an Eloquent builder hands
     * back the *underlying* query, and the underlying query does not
     * know that `Employee` is soft-deleted and `Site` is soft-deleted.
     * Skipping it would return a directory of former employees.
     *
     * @param  array<string, string>  $filters
     */
    private function query(ReportDefinition $definition, array $filters): Builder
    {
        $query = ($definition->builder)();

        ReportFilters::apply($query, $definition, $filters);

        return $query->applyScopes()->getQuery();
    }
}
