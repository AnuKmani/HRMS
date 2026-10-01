<?php

namespace App\Http\Resources;

use App\Models\EmployeeDocument;
use App\Models\OnboardingRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the onboarding checklist: a requirement, where it stands, and
 * the evidence for that answer.
 *
 * The payload is a *computed array* rather than a model, which is the whole
 * shape of this module: the requirement is a row, the evidence is a row in
 * another table, and the state is neither — it is the answer to a question
 * asked of both, recomputed every read so it cannot go stale.
 *
 * `state` is one of five and each one tells the caller something different
 * about what to do next: `missing` is the employee, `pending_verification`
 * is HR, `rejected` is the employee with a reason in `rejection_reason`,
 * `expired` is a date, and `satisfied` is nobody. Collapsing them into a
 * single boolean would discard exactly the distinction scope item M asks
 * for.
 *
 * `missing_fields` appears only for a `data` requirement — it names the
 * employee columns that are still empty, so "personal information" becomes
 * "phone and nationality" rather than an unexplained red chip.
 *
 * `document` is the row the state was decided from, not merely the newest:
 * see OnboardingService::representative() for why the two differ and why
 * showing the other one would put a chip and a preview that disagree on the
 * same screen.
 */
class OnboardingChecklistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{requirement: OnboardingRequirement, state: string, document: EmployeeDocument|null, missing_fields: array<int, string>} $item */
        $item = $this->resource;

        $requirement = $item['requirement'];

        return [
            'code' => $requirement->code,
            'name' => $requirement->name,
            'description' => $requirement->description,
            'kind' => $requirement->kind,
            'is_mandatory' => $requirement->isMandatory(),
            'sort_order' => (int) $requirement->sort_order,
            'state' => $item['state'],
            'missing_fields' => $item['missing_fields'],
            'document' => $item['document'] === null
                ? null
                : new EmployeeDocumentResource($item['document']),
        ];
    }
}
