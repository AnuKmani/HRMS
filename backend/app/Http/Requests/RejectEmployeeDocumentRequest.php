<?php

namespace App\Http\Requests;

use App\Models\EmployeeDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/employee-documents/{document}/reject
 *
 * Refusing a document needs one thing a bare `status` change would not
 * have: a reason the employee can act on. `reason` is therefore `required`
 * rather than `nullable`, because "rejected" with no explanation is a dead
 * end — the person on the other end has to guess what to upload instead,
 * and guessing wrong means another round trip for both of them.
 *
 * `status` is `prohibited` here for the reason it is prohibited everywhere
 * else in this module: the rejection is a decision with its own action, its
 * own permission and its own 409 for "already rejected", and letting a
 * payload set the word directly would route around all three.
 */
class RejectEmployeeDocumentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the document was rejected so the employee knows what to change.',
            'status.prohibited' => 'The server decides a document\'s status.',
        ];
    }

    /**
     * Authorised on the row rather than here: EmployeeDocumentPolicy::reject
     * is `documents.verify` plus being able to read the row, minus being its
     * own owner — the same three conditions `verify()` asks, because a
     * caller who may not say yes may not say no either.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $document = $this->route('document');

        if ($user === null || ! $document instanceof EmployeeDocument) {
            return false;
        }

        return $user->can('reject', $document);
    }
}
