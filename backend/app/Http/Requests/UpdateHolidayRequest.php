<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\Holiday;

/**
 * PUT /api/v1/holidays/{holiday}
 *
 * Same table as creating, relaxed to `sometimes`. The site requirement still
 * applies through `withValidator()` inherited from the parent — a PUT that
 * changes a holiday's type to `site` has to name the site, and one that does
 * not mention `type` at all leaves whatever the row already had.
 */
class UpdateHolidayRequest extends StoreHolidayRequest
{
    use MakesRequiredRulesOptional;

    public function authorize(): bool
    {
        $holiday = $this->route('holiday');

        if (! $holiday instanceof Holiday) {
            return parent::authorize();
        }

        return $this->user()?->can('update', $holiday) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->relaxRequired(parent::rules());
    }
}
