<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEmployeeDocuments;
use App\Models\Employee;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorAlias;

/**
 * POST /api/v1/employee-documents
 *
 * What a client may claim about an employment document, and who they may
 * file it for.
 *
 * **The target is decided here, not in the controller.** `employee_id` is
 * optional and means "me" when absent, which is the shape self-service
 * onboarding wants: an Employee attaching their own passport does not have
 * to say so, and an HR Executive attaching somebody else's does. Whether
 * that is permitted is {@see Visibility::mayFileDocumentsFor()} — your own
 * with `documents.create`, anybody's only with `documents.manage` — asked
 * in `authorize()` so it is answered before a single byte of the payload is
 * examined.
 *
 * **A nonexistent colleague and a real one you may not file for both answer
 * 403.** `authorize()` resolves the id and returns false when it resolves
 * to nothing, so this endpoint never distinguishes "that person exists and
 * is not yours" from "there is no such person". For a directory of people
 * that is the right answer rather than a shortcut: an `exists` 422 on one
 * and a 403 on the other would let a caller walk the employee table.
 *
 * **The type's own rules are data, not rules.** Whether a number, an issue
 * date or an expiry is expected is read from `document_types` — see
 * ValidatesEmployeeDocuments for why that check runs in `after()` and how
 * one implementation serves both this request and its update counterpart.
 *
 * **`status` and the reviewer columns are `prohibited`, not ignored.** The
 * lifecycle is five transitions EmployeeDocumentService owns and there is
 * no endpoint that sets `status` directly; silently dropping a field a
 * client believed it had set is how an API ends up with a client that
 * thinks it verified something. `prohibited` says "this endpoint never
 * accepts it" with a proper 422.
 */
class StoreEmployeeDocumentRequest extends FormRequest
{
    use ValidatesEmployeeDocuments;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $employee = $this->targetEmployee($user);

        if ($employee === null) {
            return false;
        }

        return Visibility::mayFileDocumentsFor($user, $employee);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Decided in authorize(), not here — see the class note.
            'employee_id' => ['sometimes', 'integer'],

            'document_type_id' => [
                'required',
                'integer',
                Rule::exists('document_types', 'id')->where('status', 'active'),
            ],

            'document_number' => ['nullable', 'string', 'max:100'],

            // A document may already have lapsed: HR filing last year's
            // visa is history, not a mistake, and `after:today` would
            // refuse to record the thing that needs chasing.
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_date' => ['nullable', 'date'],

            'notes' => ['nullable', 'string', 'max:1000'],

            'file' => $this->fileRules(),

            'status' => ['prohibited'],
            'uploaded_by' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'verified_by' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'expiry_notified_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $kilobytes = max(1, (int) config('hrms.storage.document_max_kilobytes', 10240));

        return [
            'document_type_id.exists' => 'Select a valid document type.',
            'file.mimes' => 'Attach a PDF, JPG, PNG or WebP.',
            'file.mimetypes' => 'Attach a PDF, JPG, PNG or WebP.',
            'file.max' => sprintf(
                'That file is larger than the %s MB limit for employee documents.',
                number_format($kilobytes / 1024, 1),
            ),
            'file.file' => 'That upload did not arrive as a file.',
            'status.prohibited' => 'The server decides a document\'s status.',
            'verified_by.prohibited' => 'Verification happens through the verify action.',
            'verified_at.prohibited' => 'Verification happens through the verify action.',
        ];
    }

    public function withValidator(ValidatorAlias $validator): void
    {
        $this->enforceTypeRequirements($validator);
        $this->enforceDateOrder($validator);
    }

    /**
     * Absent means me; present must resolve, or the whole request is
     * refused before validation runs.
     */
    private function targetEmployee($user): ?Employee
    {
        $id = $this->input('employee_id');

        if ($id === null || $id === '') {
            return $user->employee;
        }

        if (! is_scalar($id) || ! is_numeric((string) $id)) {
            return null;
        }

        return Employee::query()->find((int) $id);
    }

    /**
     * The target employee the file is being written to: the resolved
     * `employee_id` on a create, or nothing when there was none.
     *
     * Null only when authorize() said no.
     */
    public function target(): ?Employee
    {
        $user = $this->user();

        return $user === null ? null : $this->targetEmployee($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function documentPayload(): array
    {
        $data = $this->validated();
        unset($data['employee_id']);

        return $data;
    }
}
