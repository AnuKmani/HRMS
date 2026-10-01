<?php

namespace App\Http\Requests\Concerns;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Rules\CertificateContent;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator as ValidatorAlias;

/**
 * The rules that depend on *which kind* of document is being filed.
 *
 * Whether a number is expected, whether an issue date is expected and
 * whether it expires are all answers `document_types` already holds — see
 * that table for why they are data rather than a `required_if` chain nobody
 * could reconfigure. So the check reads the row and reports on the field
 * that is actually missing, which is the same shape
 * StoreHolidayRequest uses for its own cross-field requirement.
 *
 * **It runs in `after()` rather than as a rule on the attribute.** The three
 * questions are conditional on a database row rather than on the payload,
 * and the messages have to name the *reason* ("this document type requires
 * a expiry date") rather than merely assert presence.
 *
 * **One check serves create and update, and the difference between them is
 * one line.** A PUT may omit a field, in which case the existing value is
 * kept and there is nothing to require; a PUT may also *clear* a required
 * field, which is refused just as an omitted one would be on a POST. The
 * alternative — two near-identical tables in two requests — is the shape
 * that eventually lets an update quietly accept what a create would not.
 */
trait ValidatesEmployeeDocuments
{
    /**
     * Which document type the payload is filing, resolved from the payload
     * first and from the row being edited second.
     *
     * The fallback is what lets an update omit `document_type_id` entirely
     * and still be told whether the number it just cleared was required.
     */
    protected function documentType(): ?DocumentType
    {
        $route = $this->route('document');

        if ($route instanceof EmployeeDocument) {
            return $route->documentType;
        }

        $id = $this->input('document_type_id');

        if ($id === null || ! is_scalar($id)) {
            return null;
        }

        return DocumentType::query()->find((int) $id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fileRules(): array
    {
        $kilobytes = max(1, (int) config('hrms.storage.document_max_kilobytes', 10240));

        return [
            'nullable',
            'file',
            'max:'.$kilobytes,
            'mimes:pdf,jpg,jpeg,png,webp',
            'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
            new CertificateContent,
        ];
    }

    protected function enforceTypeRequirements(ValidatorAlias $validator): void
    {
        $validator->after(function (ValidatorAlias $validator) {
            $type = $this->documentType();

            if ($type === null) {
                // `document_type_id` failed its own `exists` rule, or this
                // is an update that never named a type. Either way the
                // field-level error is the useful one; piling three more on
                // top of it would bury it.
                return;
            }

            $existing = $this->route('document');
            $existing = $existing instanceof EmployeeDocument ? $existing : null;

            $required = [
                'document_number' => $type->requires_document_number,
                'issue_date' => $type->requires_issue_date,
                'expiry_date' => $type->requires_expiry_date,
            ];

            foreach ($required as $field => $isRequirement) {
                if (! $isRequirement) {
                    continue;
                }

                if ($this->filled($field)) {
                    continue;
                }

                // A PUT that left it alone keeps the value already on the
                // row; anything else — cleared, or never there in the first
                // place — is refused here.
                if ($existing !== null && $existing->getAttribute($field) !== null) {
                    continue;
                }

                $validator->errors()->add(
                    $field,
                    sprintf('This document type requires a %s.', str_replace('_', ' ', $field)),
                );
            }
        });
    }

    /**
     * An expiry that precedes its issue date is a document nobody could
     * have used, and neither field's rule can see the other.
     */
    protected function enforceDateOrder(ValidatorAlias $validator): void
    {
        $validator->after(function (ValidatorAlias $validator) {
            if (! $this->filled('issue_date') || ! $this->filled('expiry_date')) {
                return;
            }

            // A field that already failed `date` has a value nobody can
            // parse; saying "must be after the issue date" on top of "not a
            // date" would bury the useful message.
            if ($validator->errors()->hasAny(['issue_date', 'expiry_date'])) {
                return;
            }

            $issue = Carbon::parse($this->input('issue_date'));
            $expiry = Carbon::parse($this->input('expiry_date'));

            if ($expiry->lte($issue)) {
                $validator->errors()->add('expiry_date', 'The expiry date must be after the issue date.');
            }
        });
    }
}
