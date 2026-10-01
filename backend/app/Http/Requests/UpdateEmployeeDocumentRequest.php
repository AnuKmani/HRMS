<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\EmployeeDocument;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/employee-documents/{document}
 *
 * The same table as the request it extends, relaxed for the one difference
 * a PUT has: it may carry a subset. That is exactly what
 * MakesRequiredRulesOptional does — an edit *is* a create, just later — and
 * doing it this way rather than by writing a second rules() is what stops
 * the two tables drifting apart whenever a field is added.
 *
 * Three overrides sit on top of it, each for a reason the relaxation does
 * not cover:
 *
 *  - **`document_type_id` may be repeated but never changed.** A document
 *    filed as a passport does not become a visa, and the type is what the
 *    onboarding checklist, the expiry window and the required-field rules
 *    all key off. `Rule::in([...])` rather than `prohibited`, so a client
 *    that always sends the whole record keeps working.
 *
 *  - **`employee_id` is refused outright.** Moving a document from one
 *    person's file to another's is not an edit; it would silently satisfy
 *    or unsatisfy somebody else's checklist. There is no endpoint for it.
 *
 *  - **`status` stays `prohibited`.** The relaxation above only touches the
 *    exact string `required`, so the lifecycle is still the service's to
 *    move — but it is worth saying again here, where a reader of just this
 *    file would otherwise wonder.
 *
 * Authorisation is the row, not the coarse gate: `update` on
 * EmployeeDocumentPolicy is your own document or `documents.manage`. An
 * ordinary Employee holds `documents.update` for exactly this reason and
 * no more.
 */
class UpdateEmployeeDocumentRequest extends StoreEmployeeDocumentRequest
{
    use MakesRequiredRulesOptional;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->relaxRequired(parent::rules());

        $document = $this->route('document');
        $typeId = $document instanceof EmployeeDocument ? $document->document_type_id : null;

        $rules['document_type_id'] = ['sometimes', 'integer', Rule::in([$typeId])];
        $rules['employee_id'] = ['prohibited'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'document_type_id.in' => 'A document\'s type cannot be changed after it is filed.',
            'employee_id.prohibited' => 'A document cannot be moved between employees.',
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $document = $this->route('document');

        if ($user === null || ! $document instanceof EmployeeDocument) {
            return false;
        }

        return $user->can('update', $document);
    }
}
