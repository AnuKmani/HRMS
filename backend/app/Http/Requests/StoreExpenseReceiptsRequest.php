<?php

namespace App\Http\Requests;

use App\Models\Expense;
use App\Rules\CertificateContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/expenses/{expense}/receipts
 *
 * One request object for the whole batch — receipts arrive a few at a time
 * rather than inside the create payload, because a claim saved on one bar of
 * signal should not have to re-upload its evidence on every correction.
 *
 * Four checks before anything is written: declared extension (`mimes`),
 * sniffed content type (`mimetypes`), size from the same config the storage
 * layer re-checks, and whether the bytes are what they claim to be.
 *
 * That last check is {@see CertificateContent} — deliberately. "The first
 * 1024 bytes are `%PDF-` somewhere, or a header `getimagesize()` accepts" is
 * a statement about files, not about medical notes, and a second copy of it
 * written for receipts would be a second place for the two to drift apart.
 * A 3 KB of garbage renamed `note.pdf` passes `mimes` and `mimetypes`
 * (both read the *name*) and is exactly what this rule refuses.
 *
 * Authorization is instance-level: you may attach evidence to a claim you
 * could already edit, which is ExpensePolicy::storeReceipt() — your own, or
 * anybody's with `expenses.manage`. Whether the claim is in a state that can
 * still accept one is the service's 409, not this file's 403.
 */
class StoreExpenseReceiptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense
            ? ($this->user()?->can('storeReceipt', $expense) ?? false)
            : false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) config('hrms.storage.expense_receipt_max_kilobytes', 5120);

        return [
            // Six per batch, with ExpenseService's ten-per-claim ceiling on
            // top: the batch limit is about one request's weight, the other
            // about how many documents a single claim may end up carrying.
            'receipts' => ['required', 'array', 'min:1', 'max:6'],
            'receipts.*' => [
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:'.$maxKilobytes,
                new CertificateContent,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipts.required' => 'Attach at least one receipt.',
            'receipts.min' => 'Attach at least one receipt.',
            'receipts.max' => 'Attach up to six receipts at a time.',
            'receipts.*.mimes' => 'A receipt must be a PDF, JPG, PNG or WebP file.',
            'receipts.*.mimetypes' => 'A receipt must be a PDF, JPG, PNG or WebP file.',
            'receipts.*.max' => 'That receipt is too large. Keep it under the configured limit.',
            'receipts.*.certificate_content' => 'That file is not a PDF or an image a reader could open.',
        ];
    }
}
