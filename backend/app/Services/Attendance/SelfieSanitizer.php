<?php

namespace App\Services\Attendance;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * The difference between "a photograph" and "a file named like one".
 *
 * Everything a camera writes *about* the frame rather than *in* it — the
 * EXIF block with its GPS fix, the make and model, the lens, the software
 * string, sometimes a thumbnail of an entirely different picture — lives in
 * APP segments that a decoder is free to skip. So the technique here has no
 * separate "strip the metadata" step: metadata is never read, because it
 * cannot survive a trip through a pixel buffer. Decode with GD, encode with
 * GD, store what comes out. Nothing else comes along.
 *
 * That makes this class the security boundary rather than a formatter:
 *
 *  - **it only stores its own output.** The upload is read, decoded and
 *    discarded; the bytes handed to `SelfieStore` are the encoder's. A
 *    client that skips the Flutter compressor gets the identical treatment,
 *    which is the whole point — the app's pre-upload stripping is a
 *    courtesy to a patchy site signal, not a control.
 *  - **it fails closed.** Anything it cannot decode is refused before a
 *    byte reaches the disk, and if GD is missing entirely it refuses to
 *    store *anything* rather than quietly falling back to the raw upload.
 *  - **it bounds itself.** A decoded image costs about four bytes per
 *    pixel, so the pixel budget is what stops one crafted request from
 *    asking for a gigabyte of buffer.
 *  - **it emits one format.** JPEG, always, so the extension on disk is
 *    always `.jpg` and never something the client chose.
 *
 * It never reads the client's filename, and it is never handed a path to
 * write to — `SelfieStore` mints `{employeeId}/{uuid}.jpg` and owns where
 * that lands.
 *
 * The checks in `StoreCheckInRequest` (and again in `SelfieStore::isAcceptable`)
 * deliberately repeat part of this. A storage layer that trusts a validation
 * layer it may one day stop sharing an author with is a storage layer waiting
 * for an upload bug; a validation layer that only ever answers cheap questions
 * — extension, MIME sniff, size — is one that has never seen the file.
 */
final class SelfieSanitizer
{
    /** The only thing this class ever produces. */
    public const OUTPUT_MIME_TYPE = 'image/jpeg';

    public const OUTPUT_EXTENSION = 'jpg';

    /**
     * A header-level look at the upload: is it an image, and how big?
     *
     * `getimagesize()` parses the file's own header bytes, so it says no to
     * a renamed PDF no matter what the part was called — and because it
     * stops at the header it never allocates a pixel buffer, which is what
     * makes it safe to call from a validation rule that runs on every
     * check-in.
     *
     * @return array{width: int, height: int, type: int}|null
     */
    public function probe(UploadedFile $file): ?array
    {
        if (! $file->isValid()) {
            return null;
        }

        $path = $file->getRealPath();

        if ($path === false || $path === '' || ! is_file($path)) {
            return null;
        }

        // The silence is deliberate. `getimagesize()` narrates every file
        // it cannot read, and "that is not an image" is the expected answer
        // here — an answer to return as a 422, not a warning to stack-trace.
        $size = @getimagesize($path);

        if ($size === false || (int) $size[0] < 1 || (int) $size[1] < 1) {
            return null;
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'type' => (int) $size[2],
        ];
    }

    /**
     * The most pixels one selfie may claim, bounding the buffer below.
     */
    public function pixelBudget(): int
    {
        return max(1, (int) config('hrms.storage.selfie_max_pixels', 16_777_216));
    }

    /**
     * @param  array{width: int, height: int, type: int}  $size
     */
    public function fitsBudget(array $size): bool
    {
        return ($size['width'] * $size['height']) <= $this->pixelBudget();
    }

    /**
     * Decode, re-encode, return bytes that hold nothing but pixels.
     *
     * The original file is read exactly once and is never written anywhere.
     */
    public function sanitize(UploadedFile $file): string
    {
        // Fail closed, loudly. If the decoder is unavailable the one thing
        // that must not happen is falling back to what the client sent —
        // that would turn a missing extension into a metadata bypass.
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            abort(500, 'Image sanitisation is unavailable on this server.');
        }

        $size = $this->probe($file);

        if ($size === null) {
            abort(422, 'That selfie is not a readable image. Please take a new one.');
        }

        if (! $this->fitsBudget($size)) {
            abort(422, 'That image is too large to process. Take a smaller one.');
        }

        $raw = @file_get_contents($file->getRealPath());

        if ($raw === false) {
            abort(422, 'That selfie is not a readable image. Please take a new one.');
        }

        $image = @imagecreatefromstring($raw);

        unset($raw);

        if ($image === false) {
            abort(422, 'That selfie is not a readable image. Please take a new one.');
        }

        // A JPEG has no alpha channel. For everything that does — a PNG
        // with a transparent background, a WebP cut-out — the transparency
        // is flattened onto white here rather than left for GD to composite
        // over black wherever it feels like it.
        if ($size['type'] !== IMAGETYPE_JPEG) {
            $image = $this->flatten($image);
        }

        return $this->encode($image);
    }

    /**
     * Composite a possibly-translucent image over an opaque white canvas.
     *
     * Consumes and releases `$image`; the canvas (or the original, if the
     * allocation failed) is what comes back.
     */
    private function flatten(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            imagedestroy($image);

            abort(500, 'That selfie could not be processed.');
        }

        imagealphablending($canvas, true);
        imagefilledrectangle(
            $canvas,
            0,
            0,
            $width - 1,
            $height - 1,
            imagecolorallocate($canvas, 255, 255, 255),
        );
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        imagedestroy($image);

        return $canvas;
    }

    /**
     * Write `$image` out as a JPEG at the configured quality.
     *
     * `imagejpeg()` with no destination writes to output, so the buffer is
     * opened *before* anything is written: a failed start would otherwise
     * drop raw JPEG bytes into the middle of a JSON response.
     */
    private function encode(GdImage $image): string
    {
        $quality = max(1, min(100, (int) config('hrms.storage.selfie_jpeg_quality', 85)));

        if (ob_start() === false) {
            imagedestroy($image);

            abort(500, 'That selfie could not be processed.');
        }

        imagejpeg($image, null, $quality);

        $bytes = ob_get_clean();

        imagedestroy($image);

        // The JPEG magic number is the proof that these are the encoder's
        // bytes and nothing else — not a PHP warning that found its way
        // into the buffer, not the upload we were handed.
        if (! is_string($bytes) || ! str_starts_with($bytes, "\xFF\xD8")) {
            abort(500, 'That selfie could not be processed.');
        }

        return $bytes;
    }
}
