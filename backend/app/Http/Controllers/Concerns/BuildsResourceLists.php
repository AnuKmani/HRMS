<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Query-string plumbing shared by every list endpoint.
 *
 * Kept small and dumb on purpose: filters decide *what* to match, these
 * decide *how a parameter is read*. Two rules do most of the work —
 *
 *  - an unknown `sort` falls back to the default instead of raising, so a
 *    stale link in a bookmarked report cannot 422 a user;
 *  - `direction` accepts only asc/desc, because anything else would end up
 *    interpolated into SQL by hand somewhere down the line.
 *
 * Aliases exist because callers say `department` as readily as
 * `department_id`; accepting both in one place beats spelling it out six
 * times in six controllers.
 */
trait BuildsResourceLists
{
    /**
     * Free-text search term, trimmed and never an empty string.
     */
    protected function searchTerm(Request $request): ?string
    {
        $raw = $request->query('search', $request->query('q'));

        if (! is_string($raw)) {
            return null;
        }

        $term = trim($raw);

        return $term === '' ? null : $term;
    }

    /**
     * First non-empty value among a list of accepted parameter names.
     */
    protected function param(Request $request, string ...$names): ?string
    {
        foreach ($names as $name) {
            $value = $request->query($name);

            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Neutralise the LIKE wildcards in a user's search term.
     *
     * Without this, a search for `100%` matches everything ending in `00%`'s
     * leftover — and a lone `_` matches any character, so `a_c` returns
     * "abc". `\` is escaped first so the escapes below are not themselves
     * double-escaped, and MySQL's own escape character is the default, so no
     * ESCAPE clause is needed.
     */
    protected function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    protected function sortColumn(Request $request, array $allowed, string $default): string
    {
        $requested = $request->query('sort');

        return is_string($requested) && in_array($requested, $allowed, true)
            ? $requested
            : $default;
    }

    protected function sortDirection(Request $request, string $default = 'asc'): string
    {
        $requested = strtolower((string) $request->query('direction', $default));

        return $requested === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Page size, bounded so one client cannot ask the server to build a
     * response large enough to be worth attacking.
     */
    protected function perPage(Request $request): int
    {
        return (int) min(max((int) $request->query('per_page', 20), 1), 100);
    }
}
