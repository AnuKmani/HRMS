<?php

namespace App\Http\Requests;

use App\Models\SalaryCertificateRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/salary-certificate-requests
 *
 * Two fields, and the smallness is the point. `purpose` is required because
 * a certificate that says "for whatever they want" is not a document anybody
 * can audit after the fact, and `employee_id` is present because HR may file
 * one on an employee's behalf - but it defaults to the caller's own record
 * and is refused outright when it is not theirs and the caller lacks
 * `salary_certificates.manage`.
 *
 * `request_date` defaults to today rather than being required: "I need this
 * now" is the overwhelming case, and a back-dated request would be a fact
 * about when the document was asked for, which is not worth a field.
 *
 * No `status`, no `approved_by`, no `generated_at` - the decision columns
 * belong to SalaryCertificateService and to nobody else.
 */
class StoreSalaryCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SalaryCertificateRequest::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'purpose' => ['required', 'string', 'max:255'],
            'request_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Who the request may be *about*. The policy already established that
     * the caller may ask at all; this decides whose name goes on it.
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $employeeId = $this->input('employee_id');

            if ($employeeId === null || $employeeId === '') {
                return;
            }

            $own = $this->user()?->employee?->id;

            if ((int) $employeeId === (int) $own) {
                return;
            }

            if (! $this->user()?->can('salary_certificates.manage')) {
                $validator->errors()->add(
                    'employee_id',
                    'You may only request a salary certificate about yourself.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'That employee does not exist.',
            'purpose.required' => 'Say what the certificate is for - a bank, a visa application, a landlord.',
            'request_date.date_format' => 'The request date must be in YYYY-MM-DD form.',
        ];
    }
}
