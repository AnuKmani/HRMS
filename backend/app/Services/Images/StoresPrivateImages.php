<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The half of "store a photograph privately" that is the same for every
 * photograph in the system.
 *
 * Two stores answer it today — attendance selfies (Phase 5) and site report
 * photographs (Phase 7) — and a third would be the point at which a
 * divergence stopped being an oversight and started being a vulnerability:
 * one directory prefix typoed in a containment check, one format added to
 * one list and not the other, one cache header forgotten. The bytes are
 * already shared (`SelfieSanitizer` re-encodes for both, stripping EXIF and
 * GPS); this trait shares everything that decides *whether an upload is
 * accepted*, *whether a stored path may be opened*, and *how a file comes
 * back out*.
 *
 * Deliberately does NOT own sanitisation: `SelfieSanitizer` is injected
 * into whichever store is writing, because what a store is allowed to write
 * is a policy decision each store makes for its own subject — but the
 * rejection of a file nobody could decode is not, and is asked here.
 *
 * The three questions, in order of cheapness:
 *
 *  - extension — the client's *idea* of the type, checked only because
 *    failing it early saves a MIME sniff for a `.pdf` renamed `.jpg`;
 *  - sniffed MIME — the first bytes' idea of the type, which is what an
 *    HTTP client cannot talk its way out of;
 *  - size — checked before anything is read, so one oversized request
 *    cannot make the server pay for reading it.
 *
 * None of the three looks at the picture. That is `ImageContent`'s job at
 * validation time and `SelfieSanitizer`'s at write time, and both are asked
 * again rather than instead: a storage layer that trusts a validation layer
 * it may one day stop sharing an author with is a storage layer waiting for
 * an upload bug.
 */
trait StoresPrivateImages
{
    /** Everything this store may accept. Image data only — never a document. */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Where these files live, relative to the private storage root.
     *
     * `private` on purpose: `storage/app/private` has no `/storage/...`
     * URL and no directory listing, so a path that leaked would still be
     * worth nothing.
     */
    abstract public function directory(): string;

    /**
     * What one upload may weigh, in kilobytes. Each store reads its own
     * config key: a face and a site photograph are the same physical
     * constraint but are tuned (and documented) apart.
     */
    abstract public function maxKilobytes(): int;

    /**
     * Cheap rejection of a file this store will not write. The real answer
     * — "does it decode?" — belongs to the sanitizer and is asked there.
     */
    public function isAcceptable(UploadedFile $file): bool
    {
        if (! $file->isValid()) {
            return false;
        }

        $maxBytes = max(1, $this->maxKilobytes()) * 1024;

        if ($file->getSize() > $maxBytes) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return false;
        }

        // Sniffed from the bytes, not from the part's Content-Type: an HTTP
        // client is free to label an upload `application/octet-stream`, and
        // refusing a perfectly good photograph for a header nobody reads
        // would be a worse failure than the one this check exists to
        // prevent. This still only reads a header — the picture itself is
        // decoded by the sanitizer, which runs for every client.
        return in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true);
    }

    /**
     * Stream a stored file back, or null when it has gone missing.
     *
     * Never a `readFile()` into memory: a 5 MB image per request is how a
     * list screen takes a server down.
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

        // The type is announced from the extension *the server minted*,
        // never from anything the upload claimed — the sanitizer only ever
        // writes one format, and the older arms exist so a row stored before
        // it is not served as the wrong type.
        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            // A stored photograph is personal or site evidence. It must
            // never sit in a proxy or a browser cache where a shared tablet
            // would hand it to whoever picks the device up next.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(?string $path): void
    {
        if ($path === null || ! $this->isSafe($path)) {
            return;
        }

        Storage::disk('local')->delete($path);
    }

    /**
     * May this stored path be opened at all, and is it still there?
     *
     * Exposed for readers that need the bytes rather than a response — the
     * PDF generator embeds a report's photographs — and it is the same
     * containment check `response()` uses, so "readable by the generator"
     * and "readable through the API" can never come apart.
     */
    public function exists(string $path): bool
    {
        return $this->isSafe($path) && Storage::disk('local')->exists($path);
    }

    /**
     * Path containment. Cheap, and it is the one thing standing between a
     * corrupted row and `storage/app/private/../../../.env`.
     */
    private function isSafe(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }

        return str_starts_with($path, $this->directory().'/');
    }
}
