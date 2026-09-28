<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/payroll-adjustments/{adjustment}/{approve|reject|cancel}
 *
 * A remark and nothing else - see ActOnLoan for why one object covers the
 * whole set and why this body never answers a state or permission question.
 */
class ActOnPayrollAdjustment extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
