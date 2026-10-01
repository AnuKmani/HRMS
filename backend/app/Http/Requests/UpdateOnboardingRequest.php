<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/onboarding/{employee}
 *
 * Authorisation is on the *staged* record rather than on the employee:
 * Laravel resolves a policy from the class of its first argument, so
 * passing the `Employee` would reach EmployeePolicy and ask whether the
 * caller may edit a directory row — the wrong question entirely. Staging an
 * unsaved `EmployeeOnboarding` (no write; EmployeeOnboardingPolicy reads only the
 * employee id) puts the question in front of EmployeeOnboardingPolicy, where it
 * belongs. See OnboardingController::show() for the same trick on GET.
 *
 * The permission itself is `onboarding.manage` alone, with no self-service
 * exception: an employee moving their own onboarding to `completed` would
 * be signing off their own requirements, which is the same thing this
 * module refuses in EmployeeDocumentPolicy::verify().
 *
 * `status` accepts all four values including `completed`, because a client
 * that only knows how to PUT ought to be able to finish the job — the
 * completion *check* is OnboardingService::complete()'s 409 naming what is
 * outstanding, not a separate door the payload could sidestep.
 */
class UpdateOnboardingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(EmployeeOnboarding::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'employee_id' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'completed_by' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Onboarding moves through draft, pending_documents, hr_review and completed.',
            'employee_id.prohibited' => 'Onboarding belongs to the employee in the URL.',
            'completed_at.prohibited' => 'The server stamps completion.',
            'completed_by.prohibited' => 'The server records who completed it.',
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $employee = $this->route('employee');

        if ($user === null || ! $employee instanceof Employee) {
            return false;
        }

        return $user->can('update', $this->stagedRecord($employee));
    }

    /**
     * The record as it stands, or an unsaved one carrying enough to answer
     * from — see the class note. Never persisted by this method.
     */
    public function stagedRecord(Employee $employee): EmployeeOnboarding
    {
        return $employee->onboarding
            ?? new EmployeeOnboarding([
                'employee_id' => $employee->id,
                'status' => EmployeeOnboarding::STATUS_DRAFT,
            ]);
    }
}
