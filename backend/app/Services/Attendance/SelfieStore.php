<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where attendance selfies live, and how they are read back.
 *
 * Three properties hold without exception:
 *
 *  - **private**. The `local` disk is `storage/app/private`, which has no
 *    `/storage/...` URL and no directory listing; Laravel's own signed-URL
 *    route refuses an unsigned request. The only way in is
 *    `GET /api/v1/attendance/{id}/selfie`, behind the attendance policy.
 *  - **unnameable**. The stored name is `{employee}/{uuid}.jpg`, minted here.
 *    A client-supplied filename never reaches the disk, so `../../evil.php`
 *    and a colleague's selfie-by-guessing are both structurally impossible.
 *  - **unexposed**. Nothing in this class returns a path to a request, and
 *    AttendanceResource reports `has_selfie`, not `_path`.
 *
 * The actual MIME/extension/size *rejection* happens in
 * StoreCheckInRequest so the user gets a 422 naming the field. The checks
 * here repeat it on purpose: a storage layer that trusts a validation layer
 * it may one day stop sharing an author with is a storage layer waiting for
 * an upload bug.
 */
final class SelfieStore
{
    /** Everything a selfie may be. Image data only — never a document. */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function store(UploadedFile $file, Employee $employee): string
    {
        if (! $this->isAcceptable($file)) {
            abort(422, 'That selfie is not an accepted image. Please take a new one.');
        }

        $directory = $this->directory();

        // Mime type decides the extension, not the client's filename — the
        // same reason a passport office reads the page, not the cover.
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $name = $employee->id.'/'.Str::uuid()->toString().'.'.$extension;
        $path = $directory.'/'.$name;

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Stream a stored selfie back, or null when it has gone missing.
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

        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            // A selfie is personal data. It must never sit in a proxy or a
            // browser cache where a shared tablet would hand it to the next
            // person who picks the device up.
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

    public function directory(): string
    {
        return trim((string) config('hrms.storage.selfie_directory', 'attendance-selfies'), '/');
    }

    public function isAcceptable(UploadedFile $file): bool
    {
        if (! $file->isValid()) {
            return false;
        }

        $maxBytes = max(1, (int) config('hrms.storage.selfie_max_kilobytes', 5120)) * 1024;

        if ($file->getSize() > $maxBytes) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return false;
        }

        // Sniffed from the bytes, not from the part's Content-Type: an HTTP
        // client is free to label an upload `application/octet-stream`, and
        // refusing a perfectly good selfie for a header nobody reads would
        // be a worse failure than the one this check exists to prevent.
        return in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true);
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
