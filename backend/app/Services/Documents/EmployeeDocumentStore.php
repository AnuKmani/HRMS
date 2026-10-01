<?php

namespace App\Services\Documents;

use App\Services\Attendance\SelfieSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where employee documents live, and how one is read back.
 *
 * A passport scan is the most sensitive file this application will ever
 * hold, so it gets the treatment the medical certificate and the expense
 * receipt get — private disk, no public URL, no directory listing, a
 * filename this class mints, and a response only through
 * `GET /api/v1/employee-documents/{document}/file` behind
 * EmployeeDocumentPolicy — plus one thing neither of those does: **images
 * are re-encoded.**
 *
 *  - **private** — `local` is `storage/app/private`. There is no
 *    `/storage/...` route to it and no way to enumerate the directory.
 *  - **unnameable** — the stored name is `{employeeId}/{uuid}.{ext}`. A
 *    client's filename (and anything path-like inside it) is discarded, so
 *    `../../evil.php` cannot be expressed even if the validation layer
 *    were bypassed.
 *  - **unexposed** — no path ever appears in a response. The document
 *    resource reports `has_file`, `original_name`, `mime_type` and
 *    `file_size`; `original_name` is data for a list to read and is never
 *    used to open, serve or list anything.
 *  - **checked twice** — StoreEmployeeDocumentRequest validates MIME, byte
 *    size and content for the 422; these checks repeat them here, because
 *    a storage layer that trusts a validation layer it may one day stop
 *    sharing an author with is waiting for an upload bug.
 *
 * **What happens to an image, and why it differs from a receipt.** A
 * receipt is a PDF somebody scanned once and filed. A document photograph
 * arrives from a phone camera roll carrying an EXIF block — GPS fix, camera
 * body, software string, sometimes a thumbnail of a different picture —
 * none of which a passport needs and all of which should not follow a
 * person's identity document onto a disk. So images go through
 * {@see SelfieSanitizer}, the same decoder the
 * attendance selfie goes through: metadata cannot survive a trip through a
 * pixel buffer, so there is no separate "strip the metadata" step, and the
 * bytes written are the encoder's rather than the upload's. A polyglot is
 * defeated the same way — what lands is whatever GD just produced, which
 * cannot also be a script.
 *
 * **What happens to a PDF, and why it is different again.** Nothing. A
 * document that is not byte-identical to the one that was filed stops
 * being a document anybody can open, and stripping PDF segments would mean
 * a parser this application does not have. The defence for a PDF is the
 * content sniff in CertificateContent (a `%PDF-` signature inside the first
 * kilobyte) plus the fact that it is stored under a name nobody chose and
 * is only ever served with `nosniff` and `attachment`.
 *
 * **The extension on disk follows the bytes, not the name.** A `.jpg` that
 * is really a PDF is stored as `.pdf`, because `mimes:` and `mimetypes:`
 * both read the content and the two had better agree. A file whose content
 * is neither is refused before a byte is written.
 */
final class EmployeeDocumentStore
{
    /** What a document may arrive as. Documents, never anything else. */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly SelfieSanitizer $sanitizer) {}

    /**
     * Write the file and report what landed.
     *
     * @return array{path: string, original_name: string, mime_type: string, file_size: int}
     */
    public function store(int $employeeId, UploadedFile $file): array
    {
        if (! $this->isAcceptable($file)) {
            abort(422, 'That file is not an accepted employee document. Attach a PDF, JPG, PNG or WebP.');
        }

        // Content decides the shape of what is stored, never the filename:
        // finfo reads the bytes, and a `.jpg` full of PDF is a PDF.
        $isPdf = $file->getMimeType() === 'application/pdf';

        if ($isPdf) {
            $bytes = @file_get_contents($file->getRealPath());

            if ($bytes === false || ! str_contains(substr($bytes, 0, 1024), '%PDF-')) {
                abort(422, 'That file is not a PDF a reader could open.');
            }

            $extension = 'pdf';
            $mimeType = 'application/pdf';
        } else {
            // Pre-checked with this module's own wording so that a caller
            // who sent an unreadable or oversized image is told about
            // *documents*, not about selfies — sanitize() is shared code
            // and its messages are attendance's. The two questions asked
            // here are the two it would ask next, so it cannot reach one
            // of its own aborts.
            $size = $this->sanitizer->probe($file);

            if ($size === null) {
                abort(422, 'That file is not a readable image.');
            }

            if (! $this->sanitizer->fitsBudget($size)) {
                abort(422, 'That image is too large to process. Photograph it smaller.');
            }

            $bytes = $this->sanitizer->sanitize($file);

            $extension = SelfieSanitizer::OUTPUT_EXTENSION;
            $mimeType = SelfieSanitizer::OUTPUT_MIME_TYPE;
        }

        $path = sprintf(
            '%s/%d/%s.%s',
            $this->directory(),
            $employeeId,
            Str::uuid()->toString(),
            $extension,
        );

        Storage::disk('local')->put($path, $bytes);

        return [
            'path' => $path,
            // Kept as data for a list to read, and never used to open,
            // serve or list anything. The client chose it; the path above
            // was not derived from it in any way.
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $mimeType,
            'file_size' => strlen($bytes),
        ];
    }

    /**
     * Stream a stored document back, or null when it has gone missing.
     *
     * `attachment` rather than `inline`: a browser handed a PDF with
     * `inline` may render it in a plugin that has already cached the
     * session of whoever opened it last, and a passport has no business
     * being drawn into a shared page.
     */
    public function response(?string $path, ?string $originalName = null): ?StreamedResponse
    {
        if ($path === null || ! $this->isSafe($path)) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mime = match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/pdf',
        };

        return $disk->response($path, $this->downloadName($originalName, $extension), [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Disposition' => 'attachment; filename="'.$this->downloadName($originalName, $extension).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Remove the stored file. Called when a document is replaced, so the
     * superseded one does not sit on disk with nothing pointing at it.
     *
     * Deliberately *not* called by archive: taking a document out of the
     * active list and destroying the evidence are two different acts, and
     * only the first one has a route.
     */
    public function remove(?string $path): void
    {
        if ($path === null || ! $this->isSafe($path)) {
            return;
        }

        Storage::disk('local')->delete($path);
    }

    public function directory(): string
    {
        return trim((string) config('hrms.storage.document_directory', 'employee-documents'), '/');
    }

    public function isAcceptable(UploadedFile $file): bool
    {
        if (! $file->isValid()) {
            return false;
        }

        $maxBytes = max(1, (int) config('hrms.storage.document_max_kilobytes', 10240)) * 1024;

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
     * A download filename a person recognises, with nothing in it that a
     * header could carry.
     *
     * The client's name is a *display* string here and nothing more: every
     * byte outside `[A-Za-z0-9 _-]` is dropped (which removes CR, LF and
     * the double quote that would let a caller close the disposition
     * attribute and append a header of their own), the length is capped, and
     * an empty result falls back to the minted extension. It is never
     * concatenated into a path.
     */
    private function downloadName(?string $originalName, string $extension): string
    {
        $safe = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) pathinfo((string) $originalName, PATHINFO_FILENAME));
        $safe = trim((string) $safe);

        if ($safe === '' || strlen($safe) > 60) {
            $safe = 'document';
        }

        return $safe.'.'.$extension;
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
