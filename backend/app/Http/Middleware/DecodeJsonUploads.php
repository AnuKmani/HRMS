<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files sent inside a JSON body, turned back into ordinary uploads.
 *
 * Multipart uploads from the mobile app do not survive the hosted
 * (serverless) front door, while JSON requests do. So the app can send
 *
 *     { ..., "_files": { "selfie": {"name", "type", "data"},
 *                        "photos": [ {"name", "type", "data"}, ... ] } }
 *
 * with `data` base64-encoded, and this middleware writes each one to a
 * temporary file and attaches it to the request as an UploadedFile —
 * exactly what a multipart upload would have produced. Nothing is trusted
 * on the way: every endpoint's own validation (type sniffing, size limits,
 * image checks) still runs on the file afterwards.
 */
class DecodeJsonUploads
{
    /** Most files one request may carry this way. */
    private const MAX_FILES = 20;

    /** Largest single file, decoded, in bytes (the endpoints' own limits are lower). */
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $files = $request->isJson() ? $request->json('_files') : null;

        if (! is_array($files)) {
            return $next($request);
        }

        $request->json()->remove('_files');
        $count = 0;

        foreach ($files as $field => $value) {
            if (! is_string($field) || $field === '' || ! is_array($value)) {
                continue;
            }

            $list = array_is_list($value);
            $converted = [];

            foreach ($list ? $value : [$value] as $entry) {
                if (++$count > self::MAX_FILES) {
                    abort(422, 'Too many files in one request.');
                }

                $converted[] = $this->toUpload($entry);
            }

            $converted = array_values(array_filter($converted));

            if ($converted !== []) {
                $request->files->set($field, $list ? $converted : $converted[0]);
            }
        }

        return $next($request);
    }

    private function toUpload(mixed $entry): ?UploadedFile
    {
        if (! is_array($entry) || ! is_string($entry['data'] ?? null)) {
            return null;
        }

        $bytes = base64_decode($entry['data'], true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);

        $name = is_string($entry['name'] ?? null) && $entry['name'] !== '' ? basename($entry['name']) : 'upload';
        $type = is_string($entry['type'] ?? null) ? $entry['type'] : null;

        // "test" mode: the file did not arrive through PHP's upload
        // handling, so is_uploaded_file() would reject it; validation of
        // its contents is unaffected.
        return new UploadedFile($path, $name, $type, UPLOAD_ERR_OK, true);
    }
}
