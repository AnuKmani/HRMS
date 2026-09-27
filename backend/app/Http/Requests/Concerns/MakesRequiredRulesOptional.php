<?php

namespace App\Http\Requests\Concerns;

/**
 * Turn a create-shaped rule table into an update-shaped one.
 *
 * A `Store*Request` and the `Update*Request` that extends it take the same
 * fields — editing a record *is* creating it, just later — so the only
 * difference between them is that a PUT may carry a subset. Repeating the
 * whole table a second time would mean two places to change whenever a field
 * is added, and one of them would eventually be forgotten.
 *
 * Only the exact string `required` is relaxed. `required_if:type,site` and
 * its friends are left alone: a conditional requirement is a rule *about the
 * payload*, not a statement about whether this is a create, and relaxing it
 * would let an update set `type` to `site` without ever naming the site.
 */
trait MakesRequiredRulesOptional
{
    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    protected function relaxRequired(array $rules): array
    {
        return array_map(
            fn (array $set) => array_map(
                fn (mixed $rule) => $rule === 'required' ? 'sometimes' : $rule,
                $set,
            ),
            $rules,
        );
    }
}
