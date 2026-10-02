<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEmployeeDocuments;
use App\Models\EmployeeTraining;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator as ValidatorAlias;

/**
 * POST /api/v1/employee-training/{training}/complete
 *
 * Record that somebody finished the course — and, where the program issues
 * one, what came out of it.
 *
 * **Its own ability, EmployeeTrainingPolicy::complete().** `training.complete`
 * is deliberately not `training.update`: recording a pass on somebody's
 * competence is a different act from correcting the date they were booked
 * on, and a role that may do one should be expressible without the other.
 *
 * **Everything here is optional, and that is not the same as free-form.**
 * `completion_date` defaults to today and the trainer, the result and the
 * certificate fields are all nullable because plenty of courses produce
 * none of them. What *is* enforced is a rule the brief asks for: a program
 * marked `certificate_required` cannot be completed without a certificate
 * issue date **or** a file. Asked here so it arrives as a field-level 422
 * naming the missing thing, and asked again by EmployeeTrainingService so a
 * client that somehow reaches the service directly gets the same refusal —
 * a rule only the form knows is a rule the API does not have.
 *
 * **The certificate's expiry may be left blank on purpose.** When it is and
 * the program carries a validity, the service computes it from the issue
 * date; when the program carries none, no expiry at all is the honest
 * answer rather than one invented by the screen. Either way a *stated*
 * expiry wins — a card the instructor dated by hand overrides the default.
 *
 * `file` goes through the same six rules an employment document's upload
 * gets, read from `ValidatesEmployeeDocuments` rather than restated, and
 * lands in the same private store under a name nobody chose.
 *
 * The date order is asked in `after()` because "expiry before issue" is a
 * statement about two fields, and its message must name both.
 */
class CompleteEmployeeTrainingRequest extends FormRequest
{
    use ValidatesEmployeeDocuments;

    public function authorize(): bool
    {
        $user = $this->user();
        $training = $this->route('training');

        if ($user === null || ! $training instanceof EmployeeTraining) {
            return false;
        }

        return $user->can('complete', $training);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'completion_date' => ['nullable', 'date'],
            'training_date' => ['nullable', 'date'],

            'trainer' => ['nullable', 'string', 'max:200'],
            'result' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:1000'],

            'certificate_number' => ['nullable', 'string', 'max:120'],
            'certificate_issue_date' => ['nullable', 'date'],
            'certificate_expiry_date' => ['nullable', 'date'],

            'file' => $this->fileRules(),

            // The server computes these, and it computes them from what was
            // just supplied rather than from a client's arithmetic.
            'status' => ['prohibited'],
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
            'file.mimes' => 'Attach a PDF, JPG, PNG or WebP.',
            'file.mimetypes' => 'Attach a PDF, JPG, PNG or WebP.',
            'file.max' => sprintf(
                'That file is larger than the %s MB limit for training certificates.',
                number_format($kilobytes / 1024, 1),
            ),
            'file.file' => 'That upload did not arrive as a file.',
            'status.prohibited' => 'The server decides the enrolment status when training is completed.',
        ];
    }

    public function withValidator(ValidatorAlias $validator): void
    {
        $validator->after(function (ValidatorAlias $validator) {
            $errors = $validator->errors();

            $issued = $this->input('certificate_issue_date');
            $expires = $this->input('certificate_expiry_date');

            if (! $errors->has('certificate_issue_date') && ! $errors->has('certificate_expiry_date')) {
                if ($issued && $expires && (string) $expires < (string) $issued) {
                    $errors->add(
                        'certificate_expiry_date',
                        'The certificate cannot expire before it was issued.',
                    );
                }
            }

            // The brief's rule: a program that promises a certificate must
            // produce one. An issue date *or* the file itself is enough —
            // plenty of courses hand over a card with no number on it — and
            // the message names the requirement rather than the field so a
            // client knows why either would satisfy it.
            $training = $this->route('training');

            if (! $training instanceof EmployeeTraining) {
                return;
            }

            if (! $training->trainingProgram?->certificate_required) {
                return;
            }

            if ($errors->has('certificate_issue_date') || $errors->has('file')) {
                return;
            }

            $hasFile = $this->hasFile('file');
            $hasIssue = (string) ($issued ?? '') !== ''
                || ($training->certificate_issue_date !== null);

            if (! $hasFile && ! $hasIssue) {
                $errors->add(
                    'certificate_issue_date',
                    'This program requires a certificate: record its issue date or attach the file.',
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function completionPayload(): array
    {
        return $this->validated();
    }
}
