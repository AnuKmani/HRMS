<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEmployeeDocuments;
use App\Models\EmployeeTraining;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorAlias;

/**
 * PUT /api/v1/employee-training/{training}
 *
 * Correct the details of an enrolment.
 *
 * **Row-scoped by the policy, not by a rule here.** `authorize()` goes
 * through EmployeeTrainingPolicy::update(), which is `training.update` *and*
 * Visibility's narrow rule — so an HR Executive can fix a date on anybody's
 * record and an Employee can fix nothing at all (they hold neither
 * permission, and their own row is read-only: a course history is HR's
 * record of you, not a form you fill in).
 *
 * **Only the live states may be set by hand.** The three forward moves are
 * `completed` through `complete` and `cancelled` through `cancel`, each
 * with its own permission and its own guard. `expired` is the scheduler's
 * alone — see TrainingExpiryService — so a client that could set it would
 * be able to declare a certificate dead without the calendar having said
 * so.
 *
 * **`file` is the certificate**, validated by the very same rules an
 * employment document's upload gets (`ValidatesEmployeeDocuments::fileRules()`
 *): PDF, JPEG, PNG or WebP, sniffed by content rather than by extension,
 * capped at the shared employee-document size. Reusing the trait rather
 * than restating six rules is the whole point of it existing — a second
 * copy is a second thing to fix when the limit changes.
 *
 * Date order is asked in `after()` because "expiry before issue" is a
 * statement about two fields together, and the message has to name the
 * fields rather than assert `lte` on one of them.
 */
class UpdateEmployeeTrainingRequest extends FormRequest
{
    use ValidatesEmployeeDocuments;

    public function authorize(): bool
    {
        $user = $this->user();
        $training = $this->route('training');

        if ($user === null || ! $training instanceof EmployeeTraining) {
            return false;
        }

        return $user->can('update', $training);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enrollment_date' => ['sometimes', 'date'],
            'training_date' => ['nullable', 'date'],
            'trainer' => ['nullable', 'string', 'max:200'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'result' => ['nullable', 'string', 'max:60'],

            'status' => ['sometimes', Rule::in(EmployeeTraining::LIVE)],

            'certificate_number' => ['nullable', 'string', 'max:120'],
            'certificate_issue_date' => ['nullable', 'date'],
            'certificate_expiry_date' => ['nullable', 'date'],

            'file' => $this->fileRules(),

            'completion_date' => ['prohibited'],
            'expiry_notified_at' => ['prohibited'],
            'created_by' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'training_program_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $kilobytes = max(1, (int) config('hrms.storage.document_max_kilobytes', 10240));

        return [
            'status.in' => 'Enrolment status may only move between enrolled, scheduled and in progress.',
            'file.mimes' => 'Attach a PDF, JPG, PNG or WebP.',
            'file.mimetypes' => 'Attach a PDF, JPG, PNG or WebP.',
            'file.max' => sprintf(
                'That file is larger than the %s MB limit for training certificates.',
                number_format($kilobytes / 1024, 1),
            ),
            'file.file' => 'That upload did not arrive as a file.',
            'completion_date.prohibited' => 'Completion is recorded through the complete action.',
            'employee_id.prohibited' => 'An enrolment cannot be moved to another person.',
            'training_program_id.prohibited' => 'An enrolment cannot be moved to another program.',
        ];
    }

    public function withValidator(ValidatorAlias $validator): void
    {
        $validator->after(function (ValidatorAlias $validator) {
            $issued = $this->input('certificate_issue_date');
            $expires = $this->input('certificate_expiry_date');

            if ($validator->errors()->has('certificate_issue_date')
                || $validator->errors()->has('certificate_expiry_date')) {
                return;
            }

            if (! $issued || ! $expires) {
                return;
            }

            if ((string) $expires < (string) $issued) {
                $validator->errors()->add(
                    'certificate_expiry_date',
                    'The certificate cannot expire before it was issued.',
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function trainingPayload(): array
    {
        return $this->validated();
    }
}
