<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/login
 *
 * Deliberately validates shape only — never whether the credentials are
 * correct. Login failure is reported by AuthController as a 401 with an
 * identical message for "unknown email" and "wrong password", so a 422 here
 * would tell an attacker which addresses exist in the system.
 */
class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // No min-length rule: this is a *known* password, not a new one.
            // A short max stops absurd payloads from reaching bcrypt.
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:190'],
        ];
    }
}
