<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/employees/{employee}/bank-account
 *
 * The one form in this application that accepts money routing information,
 * and therefore the one with the least on it.
 *
 * **No coarse `permission:` gate on the route.** The route has to admit an
 * ordinary employee reading *their own* account, which is precisely the
 * case where a shared `permission:` middleware would have to be absent — so
 * EmployeePolicy::updateBankAccount is the only thing in front of it, and
 * it asks `employees.salary.view` or `onboarding.manage`. Self-service
 * *entry* is deliberately not offered: an unverified IBAN sitting in a
 * payroll run is a failed payment nobody notices until payday, and the
 * decision to allow that belongs to an operator rather than to a default.
 *
 * **Nothing here is ever logged.** The values go into encrypted columns
 * (see EmployeeBankAccount), are returned only by
 * EmployeeBankAccountResource and never by EmployeeResource, and appear in
 * no exception message: every rule below reports on the *field*, never on
 * the value that failed.
 *
 * `currency` is checked against `system.supported_currencies` the same way
 * a claim's currency is — one allow-list in one place, and an empty setting
 * switches the membership check off rather than refusing everything, because
 * a setting an operator has not filled in is a reason to fall back to the
 * three-letter shape rule, not a reason to stop work.
 */
class UpsertEmployeeBankAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $currency = ['nullable', 'string', 'size:3', 'alpha'];
        $allowed = $this->allowedCurrencies();

        if ($allowed !== []) {
            $currency[] = Rule::in($allowed);
        }

        return [
            'account_holder_name' => ['required', 'string', 'max:150'],
            'bank_name' => ['required', 'string', 'max:150'],
            'iban' => ['required', 'string', 'max:60'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'swift_bic' => ['nullable', 'string', 'max:30'],
            'currency' => $currency,
            'status' => ['sometimes', Rule::in(EmployeeBankAccount::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'iban.required' => 'The IBAN the salary is paid into is required.',
            'iban.max' => 'That IBAN is too long.',
            'account_holder_name.required' => 'The name on the account is required.',
            'currency.in' => 'That currency is not enabled for this company.',
            'currency.size' => 'A currency code is three letters.',
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $employee = $this->route('employee');

        if ($user === null || ! $employee instanceof Employee) {
            return false;
        }

        return $user->can('updateBankAccount', $employee);
    }

    /**
     * @return array<int, string>
     */
    private function allowedCurrencies(): array
    {
        $codes = [];

        foreach (app(SettingsService::class)->json('system.supported_currencies') as $code) {
            if (! is_string($code) || trim($code) === '') {
                continue;
            }

            $codes[] = strtoupper(trim($code));
        }

        return array_values(array_unique($codes));
    }
}
