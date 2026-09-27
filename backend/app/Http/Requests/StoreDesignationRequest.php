<?php

namespace App\Http\Requests;

use App\Models\Designation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/designations
 *
 * `department_id` is nullable on purpose — "Graduate Trainee" belongs to the
 * organisation, not to one team — but when it is supplied the department must
 * still exist *and* not be archived, because silently re-parenting a
 * designation onto a soft-deleted row would create a record nothing can reach.
 */
class StoreDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('designations.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', $this->liveDepartment()],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('designations', 'code')],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(Designation::STATUSES)],
        ];
    }

    // Declared `mixed`, not `Rule`: `Rule::exists()` returns an `Exists`
    // instance, which Laravel invokes through InvokableRule rather than the
    // older Rule interface — so the return type would reject the very value
    // it documents. The expression itself is what matters to callers.
    private function liveDepartment(): mixed
    {
        return Rule::exists('departments', 'id')->whereNull('deleted_at');
    }
}
