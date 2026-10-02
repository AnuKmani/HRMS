<?php

namespace App\Http\Requests;

use App\Models\TrainingProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/training-programs/{program}
 *
 * The catalogue's own editor, and the difference between this and the store
 * request is `sometimes` and an ignoring unique rule — not a second set of
 * opinions. Same fields, same reasons, one implementation serving both:
 * an update that accepted what a create refused is how a validation table
 * eventually diverges from itself.
 *
 * **Retiring is an edit, not a delete.** `status` may walk to `retired` and
 * back; nothing here removes a program, because a cohort that ran cannot be
 * un-run and every enrolment it produced has to keep naming something real.
 *
 * **A program with people on it may still be edited.** Changing a provider
 * or a duration affects future enrolments, and existing ones keep the
 * `trainer` they recorded — see EmployeeTraining::effectiveTrainer() for
 * why the fallback is resolved rather than copied.
 */
class UpdateTrainingProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('training.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var TrainingProgram|null $program */
        $program = $this->route('program');

        return [
            'training_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('training_types', 'id')->where('status', 'active'),
            ],

            'code' => [
                'sometimes',
                'string',
                'max:60',
                Rule::unique('training_programs', 'code')->ignore($program?->id),
            ],

            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'provider' => ['nullable', 'string', 'max:200'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'certificate_required' => ['sometimes', 'boolean'],
            'certificate_validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['sometimes', Rule::in(['active', 'retired'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'training_type_id.exists' => 'Select an active training type.',
            'code.unique' => 'That training program code is already in use.',
        ];
    }
}
