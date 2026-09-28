<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Services\Images\StoresPrivateImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where attendance selfies live, and how they are read back.
 *
 * Four properties hold without exception:
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
 *  - **sanitised**. What lands on disk is `SelfieSanitizer`'s re-encoded
 *    JPEG, never the upload: the original bytes are decoded in memory and
 *    dropped, so no EXIF, no GPS fix, no camera model and no client
 *    filename survive, and one format is written whatever arrived. Why the
 *    stripping is a side effect of re-encoding rather than a step of its
 *    own is `SelfieSanitizer`'s story to tell.
 *
 * The accept/reject screen and the read-back path now come from
 * `StoresPrivateImages`, shared with the Phase 7 report-photo store — they
 * are the same three questions about an upload and the same containment
 * check about a stored path, and two copies of the second one would be two
 * chances for a path to escape its directory. Which files this class
 * *writes*, and under what name, remain this class's own decision.
 *
 * The actual MIME/extension/size *rejection* — and the `ImageContent` check
 * that the bytes decode into an image at all — happens in
 * StoreCheckInRequest so the user gets a 422 naming the field. The checks
 * here repeat it on purpose: a storage layer that trusts a validation layer
 * it may one day stop sharing an author with is a storage layer waiting for
 * an upload bug.
 */
final class SelfieStore
{
    use StoresPrivateImages;

    public function __construct(private readonly SelfieSanitizer $sanitizer) {}

    public function store(UploadedFile $file, Employee $employee): string
    {
        if (! $this->isAcceptable($file)) {
            abort(422, 'That selfie is not an accepted image. Please take a new one.');
        }

        // The bytes that go on disk are the sanitizer's, never the upload's.
        // JPEG in, JPEG out, and the decoder has already said no to anything
        // it could not read.
        $bytes = $this->sanitizer->sanitize($file);

        $directory = $this->directory();

        // Neither the client's filename nor its extension chose the format —
        // the encoder did, so the extension is known before a name is built.
        $name = $employee->id.'/'.Str::uuid()->toString().'.'.SelfieSanitizer::OUTPUT_EXTENSION;
        $path = $directory.'/'.$name;

        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    public function directory(): string
    {
        return trim((string) config('hrms.storage.selfie_directory', 'attendance-selfies'), '/');
    }

    public function maxKilobytes(): int
    {
        return (int) config('hrms.storage.selfie_max_kilobytes', 5120);
    }
}
