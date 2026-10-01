<?php

namespace App\Http\Resources;

use App\Models\EmployeeOnboarding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where one employee is in joining the company.
 *
 * **The resource wraps the *employee*, not the row.** The list endpoint is a
 * directory query — every person, with the status of the onboarding that
 * may or may not have been opened for them — because "which of these forty
 * starters has produced nothing yet?" is the question HR actually asks. A
 * query over `employee_onboarding` would silently omit everyone nobody had
 * started, and an empty list would read as "all done" rather than "not
 * begun".
 *
 * So `status` is read from the row when there is one and falls back to
 * `draft` when there is not, and `exists` says which of the two you are
 * looking at: an unsaved record has no `id`, no dates and no notes, and
 * pretending otherwise would be inventing a state nobody put there.
 *
 * **No checklist lives on this payload.** Computing it costs a query for
 * the documents and another for the bank account, and a page of twenty rows
 * would pay for twenty of each. The list answers "where is everybody?";
 * `GET /onboarding/{employee}` answers "what exactly is outstanding?" —
 * once, for one person, where the cost is expected.
 *
 * `missing_requirements` is opt-in through `?with=missing` for exactly the
 * one caller that needs it in bulk (a filtered list), so the default stays
 * cheap for everyone else.
 */
class OnboardingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->onboarding;

        return [
            'employee_id' => $this->id,
            'employee' => new EmployeeBriefResource($this->resource),
            'status' => $record?->status ?? EmployeeOnboarding::STATUS_DRAFT,
            'exists' => $record !== null,
            'is_completed' => ($record?->status) === EmployeeOnboarding::STATUS_COMPLETED,
            'started_at' => $record?->started_at,
            'completed_at' => $record?->completed_at,
            'completed_by' => $record?->completed_by,
            'notes' => $record?->notes,
            'created_at' => $record?->created_at,
            'updated_at' => $record?->updated_at,
        ];
    }
}
