<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorAlias;

/**
 * POST /api/v1/employee-training
 *
 * What a client may claim about an enrolment, and whose course history it
 * may write to.
 *
 * **The target is resolved before a byte of the payload is looked at.**
 * `employee_id` is required here (unlike documents, where absent means
 * "me") because putting somebody on a course is an act *on them*, and an
 * accidental self-enrolment is exactly what the brief rules out. Whether
 * this role may do it for that person is
 * {@see Visibility::mayAssignTrainingFor()} — `training.assign`, with no
 * self-service half — asked in `authorize()` so it is answered first.
 *
 * **A nonexistent colleague and a real one you may not enrol for both answer
 * 403.** `authorize()` resolves the id and returns false when it resolves to
 * nothing, so this endpoint never distinguishes "that person exists and is
 * not yours" from "there is no such person". For a directory of people that
 * is the right answer rather than a shortcut: an `exists` 422 on one and a
 * 403 on the other would let a caller walk the employee table.
 *
 * **Only the three live states may be set.** `completed`, `failed`,
 * `cancelled` and `expired` are reached through `complete` and `cancel`,
 * which are service methods with their own guards and their own
 * permissions. Silently accepting `status: completed` on a create would
 * hand a client the ability to pass itself a course in one round trip.
 *
 * **Duplicate live enrolment is a 409, not a 422**, and it lives in the
 * service rather than here: the payload is perfectly valid, the *world* is
 * in the way, and a validation error would tell the caller to fix a field
 * that was never wrong.
 */
class StoreEmployeeTrainingRequest extends FormRequest
{
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

        return Visibility::mayAssignTrainingFor($user, $employee);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],

            'training_program_id' => [
                'required',
                'integer',
                Rule::exists('training_programs', 'id')->where('status', 'active'),
            ],

            'enrollment_date' => ['required', 'date'],
            'training_date' => ['nullable', 'date', 'after_or_equal:enrollment_date'],

            'trainer' => ['nullable', 'string', 'max:200'],
            'remarks' => ['nullable', 'string', 'max:1000'],

            'status' => ['sometimes', Rule::in(EmployeeTraining::LIVE)],

            // The whole certificate arrives through `complete`, not here —
            // see CompleteEmployeeTrainingRequest. Accepting a certificate
            // on a booking would let a client attach a card to a course
            // nobody has sat yet.
            'certificate_number' => ['prohibited'],
            'certificate_issue_date' => ['prohibited'],
            'certificate_expiry_date' => ['prohibited'],
            'completion_date' => ['prohibited'],
            'result' => ['prohibited'],
            'file' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Select a valid employee.',
            'training_program_id.exists' => 'Select an active training program.',
            'training_date.after_or_equal' => 'The training date cannot be before the enrolment date.',
            'status.in' => 'An enrolment can only start as enrolled, scheduled or in progress.',
            'certificate_issue_date.prohibited' => 'A certificate is recorded through the complete action.',
            'certificate_expiry_date.prohibited' => 'A certificate is recorded through the complete action.',
            'completion_date.prohibited' => 'Completion is recorded through the complete action.',
            'result.prohibited' => 'The result is recorded through the complete action.',
            'file.prohibited' => 'A certificate is uploaded through the complete action.',
        ];
    }

    public function withValidator(ValidatorAlias $validator): void
    {
        // The whole request is about somebody else, so the id is resolved
        // exactly once and the answer is reused by authorize() and by the
        // controller through target().
        $validator->after(function (ValidatorAlias $validator) {
            $id = $this->input('employee_id');

            if ($validator->errors()->has('employee_id')) {
                return;
            }

            if (! is_scalar($id) || ! is_numeric((string) $id)) {
                $validator->errors()->add('employee_id', 'Select a valid employee.');
            }
        });
    }

    private function targetEmployee($user): ?Employee
    {
        $id = $this->input('employee_id');

        if (! is_scalar($id) || ! is_numeric((string) $id)) {
            return null;
        }

        return Employee::query()->find((int) $id);
    }

    /**
     * The person this enrolment is for — resolved in authorize(), or null
     * when authorize() said no.
     */
    public function target(): ?Employee
    {
        $user = $this->user();

        return $user === null ? null : $this->targetEmployee($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function trainingPayload(): array
    {
        $data = $this->validated();
        unset($data['employee_id']);

        return $data;
    }
}
