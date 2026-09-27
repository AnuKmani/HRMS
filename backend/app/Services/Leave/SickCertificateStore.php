<?php

namespace App\Services\Leave;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where medical certificates live, and how one is read back.
 *
 * A certificate is a health document about a named person, so it gets exactly
 * the treatment the attendance selfie gets — private disk, no public URL, no
 * directory listing, a filename this class mints, and a response only through
 * `GET /api/v1/leave/{id}/certificate` behind LeaveRequestPolicy:
 *
 *  - **private** — `local` is `storage/app/private`. There is no
 *    `/storage/...` route to it and no way to enumerate the directory.
 *  - **unnameable** — the stored name is `{employee}/{uuid}.{ext}`. A client's
 *    filename (and anything path-like inside it) is discarded, so
 *    `../../evil.php` cannot be expressed even if the validation layer were
 *    bypassed.
 *  - **unexposed** — no path is ever returned by an API response;
 *    LeaveResource reports `has_certificate`.
 *  - **checked twice** — StoreCertificateRequest validates MIME, byte size
 *    and content for the 422; these checks repeat them here, because a
 *    storage layer that trusts a validation layer it may one day stop
 *    sharing an author with is waiting for an upload bug.
 *
 * Unlike a selfie nothing is re-encoded: a PDF must stay a PDF or it stops
 * being a document anybody can open, and a doctor's note is not a picture
 * this app needs to normalise. Stripping metadata would mean parsing two
 * formats with no library to do it — the client's filename is dropped, which
 * is the one piece of metadata this app itself creates.
 */
final class SickCertificateStore
{
    /** What a certificate may arrive as. Documents, never anything else. */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function store(UploadedFile $file, Employee $employee): string
    {
        if (! $this->isAcceptable($file)) {
            abort(422, 'That file is not an accepted medical certificate.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'pdf';
        }

        $path = sprintf(
            '%s/%d/%s.%s',
            $this->directory(),
            $employee->id,
            Str::uuid()->toString(),
            $extension,
        );

        // Loaded as bytes rather than streamed: certificates are capped at a
        // few megabytes, and a stream that outlives the request is a file
        // handle somebody has to remember to close.
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Stream a stored certificate back, or null when it has gone missing.
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

        // `attachment` rather than `inline`: a browser handed a PDF with
        // `inline` may render it in a plugin that has already cached the
        // session of whoever opened it last, and a doctor's note has no
        // business being drawn into a shared page.
        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Disposition' => 'attachment; filename="certificate.'.($mime === 'application/pdf' ? 'pdf' : 'img').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Remove the stored file. Called when a certificate is replaced, so the
     * superseded one does not sit on disk with nothing pointing at it.
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
        return trim((string) config('hrms.storage.certificate_directory', 'leave-certificates'), '/');
    }

    public function isAcceptable(UploadedFile $file): bool
    {
        if (! $file->isValid()) {
            return false;
        }

        $maxBytes = max(1, (int) config('hrms.storage.certificate_max_kilobytes', 5120)) * 1024;

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
