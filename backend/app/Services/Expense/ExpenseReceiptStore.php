<?php

namespace App\Services\Expense;

use App\Models\Expense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where expense receipts live, and how one is read back.
 *
 * A receipt is somebody's invoice, fare docket or card slip filed against a
 * named person's claim for money, so it gets exactly the treatment the
 * medical certificate gets — private disk, no public URL, no directory
 * listing, a filename this class mints, and a response only through
 * `GET /api/v1/expenses/{expense}/receipts/{receipt}` behind ExpensePolicy:
 *
 *  - **private** — `local` is `storage/app/private`. There is no
 *    `/storage/...` route to it and no way to enumerate the directory.
 *  - **unnameable** — the stored name is `{expense}/{uuid}.{ext}`. A
 *    client's filename (and anything path-like inside it) is discarded, so
 *    `../../evil.php` cannot be expressed even if the validation layer were
 *    bypassed.
 *  - **unexposed** — no path is ever returned by an API response; the
 *    receipt resource reports `original_name`, `mime_type` and
 *    `size_bytes`. `original_name` is data for a list to read, never
 *    something this class opens a file with, and it is never echoed into a
 *    response header either — a filename belongs to a header only once it
 *    has been minted here.
 *  - **checked twice** — StoreExpenseReceiptsRequest validates MIME, byte
 *    size and content for the 422; these checks repeat them here, because a
 *    storage layer that trusts a validation layer it may one day stop
 *    sharing an author with is waiting for an upload bug.
 *
 * Unlike a selfie nothing is re-encoded: a receipt must stay the document
 * it was (a PDF that is re-encoded stops opening), and there is no pixel
 * budget to protect because nothing here decodes an image. What IS stripped
 * is the client's filename — the one piece of metadata this app itself
 * creates.
 */
final class ExpenseReceiptStore
{
    /** What a receipt may arrive as. Documents and images, never anything else. */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function store(UploadedFile $file, Expense $expense): string
    {
        if (! $this->isAcceptable($file)) {
            abort(422, 'That file is not an accepted receipt.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'pdf';
        }

        $path = sprintf(
            '%s/%d/%s.%s',
            $this->directory(),
            $expense->id,
            Str::uuid()->toString(),
            $extension,
        );

        // Loaded as bytes rather than streamed: receipts are capped at a
        // few megabytes, and a stream that outlives the request is a file
        // handle somebody has to remember to close.
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Stream a stored receipt back, or null when it has gone missing.
     */
    public function response(string $path): ?StreamedResponse
    {
        if (! $this->isSafe($path)) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        // `attachment` rather than `inline`, and a name minted here rather
        // than the uploader's: a browser handed a PDF with `inline` may
        // render it in a plugin that has already cached somebody else's
        // session, and the stored `original_name` is client input that has
        // no business being placed in a response header.
        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Disposition' => 'attachment; filename="receipt.'.($mime === 'application/pdf' ? 'pdf' : 'img').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Remove a stored file. Called when a receipt is deleted, so the bytes
     * do not sit on disk with no row pointing at them.
     */
    public function delete(?string $path): void
    {
        if ($path === null || ! $this->isSafe($path)) {
            return;
        }

        Storage::disk('local')->delete($path);
    }

    public function directory(): string
    {
        return trim((string) config('hrms.storage.expense_receipt_directory', 'expense-receipts'), '/');
    }

    public function isAcceptable(UploadedFile $file): bool
    {
        if (! $file->isValid()) {
            return false;
        }

        $maxBytes = max(1, (int) config('hrms.storage.expense_receipt_max_kilobytes', 5120)) * 1024;

        if ($file->getSize() > $maxBytes) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return false;
        }

        // Sniffed from the bytes via finfo, not from the part's Content-Type:
        // an HTTP client may label an upload anything it likes, and trusting
        // the header is precisely the hole this check exists to close.
        return in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true);
    }

    /**
     * Path containment — the one thing standing between a corrupted row and
     * `storage/app/private/../../../.env`.
     */
    private function isSafe(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }

        return str_starts_with($path, $this->directory().'/');
    }
}
