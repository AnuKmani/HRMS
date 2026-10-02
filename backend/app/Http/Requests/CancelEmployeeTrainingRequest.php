<?php

namespace App\Http\Requests;

use App\Models\EmployeeTraining;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/employee-training/{training}/cancel
 *
 * Take an enrolment off the books.
 *
 * **A reason field, not a reason requirement.** `remarks` is nullable: some
 * cancellations have one sentence of context and some have none, and
 * refusing an empty one would push callers into typing "n/a" into a field
 * that then means "n/a". EmployeeTrainingService appends rather than
 * overwrites when a remark *is* supplied, so the cancellation does not
 * quietly erase what the booking said.
 *
 * **No `status` in the payload.** This endpoint's *whole* job is to set one
 * specific status; a `status` field beside it would be a second way to say
 * the same thing and a first way to say a different one. It is `prohibited`
 * rather than ignored for the reason documents prohibit their server-owned
 * columns: silently dropping a field a client believed it had set is how an
 * API ends up with a client that thinks it cancelled something.
 *
 * Whether this row may be cancelled at all is EmployeeTrainingPolicy::
 * cancel() — `training.update` **and** Visibility's narrow rule — and
 * whether it is *still* cancellable is EmployeeTrainingService's 409
 * (`already cancelled`, `completed`, `lapsed`). Two questions, two answerers,
 * and neither pretends to be the other.
 */
class CancelEmployeeTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $training = $this->route('training');

        if ($user === null || ! $training instanceof EmployeeTraining) {
            return false;
        }

        return $user->can('cancel', $training);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],

            'status' => ['prohibited'],
            'completion_date' => ['prohibited'],
            'certificate_number' => ['prohibited'],
            'certificate_issue_date' => ['prohibited'],
            'certificate_expiry_date' => ['prohibited'],
            'file' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'This action cancels the enrolment; it accepts no status.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelPayload(): array
    {
        return $this->validated();
    }
}
