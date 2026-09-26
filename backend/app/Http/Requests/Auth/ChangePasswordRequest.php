<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/auth/change-password
 *
 * The current-password proof lives here rather than in the controller so the
 * rule travels with the endpoint and cannot be forgotten when the controller
 * is reused. Verified with Hash::check instead of the `current_password`
 * validation rule because that rule resolves the user from the *default*
 * guard, which is not guaranteed to be the Sanctum guard on an API request.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Reachable only behind auth:sanctum, so the caller is always a user.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                // letters + numbers + minimum length: enough to stop
                // "password1"-class secrets without a hostile complexity
                // maze users will work around with "Password1!".
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            if (! Hash::check((string) $this->input('current_password'), $user->password)) {
                $validator->errors()->add(
                    'current_password',
                    'The current password you entered is incorrect.'
                );
            }
        });
    }
}
