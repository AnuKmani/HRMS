<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/training-programs
 *
 * What a client may claim about a configurable training program.
 *
 * **No row scope, because a program is not a row about a person.** The
 * catalogue is configuration: `training.create` decides who may add to it
 * and that is the whole answer. The narrow rules this module is known for
 * live on enrolments, where the row actually describes somebody.
 *
 * **`code` is unique and `training_type_id` must be an active type.** The
 * code is the stable identity — what a re-seeded vocabulary, a report and a
 * future import all match on — so two programs may not share one. The type
 * has to be active rather than merely present: filing a program under a
 * retired kind would put a real course in a form nobody can choose from.
 *
 * **`certificate_validity_days` may be null even when a certificate *is*
 * required**, and that is not an oversight. Zero would mean a card that
 * expires the day it is issued; null means "this program's certificate
 * never lapses", which is a real answer. `certificate_required` only says a
 * piece of paper comes out — it says nothing about how long the paper is
 * good for.
 *
 * `status` defaults in the service rather than in these rules, so an
 * omission and an explicit `active` cannot end up meaning two things.
 */
class StoreTrainingProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('training.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'training_type_id' => [
                'required',
                'integer',
                Rule::exists('training_types', 'id')->where('status', 'active'),
            ],

            'code' => [
                'required',
                'string',
                'max:60',
                Rule::unique('training_programs', 'code'),
            ],

            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'provider' => ['nullable', 'string', 'max:200'],

            // `min:1` rather than `min:0`: a zero-day course is not a
            // course, and `nullable` already carries the honest answer to
            // "we do not track how long it takes".
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
            'certificate_validity_days.min' => 'Enter how many days the certificate is valid for, or leave it empty if it never expires.',
        ];
    }
}
