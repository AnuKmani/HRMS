<?php

namespace App\Http\Requests;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/expenses/{expense}/{submit|approve|reject|cancel}
 *
 * One request object for all four transitions, because none of them takes
 * anything but a remark. What they are allowed to *do* is decided by the
 * policy — may you, on this claim, right now? — and by the service — is it
 * in a state where that transition exists? Not by anything in this body.
 *
 * `remarks` is required on **reject** and nowhere else. A refusal with no
 * reason attached is a decision nobody can learn from, and the remark is
 * what the employee reads on their claim; a submission and a cancellation
 * are self-explanatory, so making them all require a remark would only
 * produce the empty string.
 *
 * The rejection remark is stored on the approval record that was refused,
 * not on the claim: "who said no, and why" is a property of a step of the
 * chain, and the chain is where the timeline reads it from.
 */
class ActOnExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The same policy answer the controller asks again a moment later —
        // asked *first*. Validation runs between a FormRequest's authorize()
        // and the controller, so without this an out-of-chain approver would
        // be told what is wrong with their body before being told the claim
        // is none of their business. One policy asked twice cannot disagree
        // with itself; two implementations of the same rule could.
        //
        // The ability is the last segment of the path — submit, approve,
        // reject, cancel — and those four names are ExpensePolicy's, because
        // the transitions are the policy's and not this request's.
        $expense = $this->route('expense');

        return $expense instanceof Expense
            ? ($this->user()?->can(basename($this->path()), $expense) ?? false)
            : false;
    }

    /**
     * `required` when this is a rejection, `otherwise` a plain optional
     * field. The two cannot be combined as `sometimes` + `required` —
     * `sometimes` skips the whole rule set when the key is absent, so the
     * required rule underneath would never fire and the remark would be
     * optional in exactly the case it is meant to be mandatory.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'remarks' => array_merge(
                $this->isRejecting() ? ['required'] : ['sometimes', 'nullable'],
                ['string', 'max:500'],
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'remarks.required' => 'Give a reason for rejecting this claim — the employee sees it.',
            'remarks.max' => 'Keep the remark to 500 characters.',
        ];
    }

    private function isRejecting(): bool
    {
        return str_ends_with($this->path(), '/reject');
    }
}
