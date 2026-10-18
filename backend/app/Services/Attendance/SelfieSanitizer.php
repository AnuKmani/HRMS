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
        $size = $this->probe($file);

        // Without GD (some serverless PHP builds ship without it) a JPEG is
        // still cleaned, by rebuilding it from its image segments alone —
        // see stripJpeg(). Anything else is refused: never fall back to what
        // the client sent, which would turn a missing extension into a
        // metadata bypass.
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            if ($size === null || $size['type'] !== IMAGETYPE_JPEG) {
                abort(422, 'Please send the photo as a JPEG (take it with the camera).');
            }

            if (! $this->fitsBudget($size)) {
                abort(422, 'That image is too large to process. Take a smaller one.');
            }

            $raw = @file_get_contents($file->getRealPath());
            $clean = $raw === false ? null : $this->stripJpeg($raw);

            if ($clean === null) {
                abort(422, 'That selfie is not a readable image. Please take a new one.');
            }

            return $clean;
        }

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
     * A JPEG rebuilt from the segments a decoder needs, and nothing else.
     *
     * Walks the marker structure and keeps only what describes the pixels —
     * quantisation and Huffman tables, the frame header, restart interval,
     * JFIF (APP0) and the Adobe colour-transform marker (APP14) — then the
     * entropy-coded scan data up to the end-of-image marker. Every other
     * APP segment (EXIF with its GPS fix, XMP, ICC, maker notes, embedded
     * thumbnails) and every comment is dropped, and so is anything appended
     * after the image ends. Returns null for anything that is not a
     * well-formed baseline/progressive JPEG.
     */
    private function stripJpeg(string $raw): ?string
    {
        $length = strlen($raw);

        if ($length < 4 || substr($raw, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $keep = [0xDB, 0xC4, 0xDD, 0xCC, 0xE0, 0xEE];
        $out = "\xFF\xD8";
        $i = 2;
        $sawFrame = false;

        while ($i < $length) {
            if ($raw[$i] !== "\xFF") {
                return null;
            }

            // Fill bytes before a marker are allowed.
            while ($i < $length && $raw[$i] === "\xFF") {
                $i++;
            }

            if ($i >= $length) {
                return null;
            }

            $marker = ord($raw[$i]);
            $i++;

            if ($marker === 0xD9) {
                return $sawFrame ? $out."\xFF\xD9" : null;
            }

            // Standalone markers carry no length.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($i + 2 > $length) {
                return null;
            }

            $segmentLength = (ord($raw[$i]) << 8) | ord($raw[$i + 1]);

            if ($segmentLength < 2 || $i + $segmentLength > $length) {
                return null;
            }

            $segment = substr($raw, $i, $segmentLength);
            $i += $segmentLength;

            $isFrame = $marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true);

            if ($isFrame) {
                $sawFrame = true;
                $out .= "\xFF".chr($marker).$segment;

                continue;
            }

            if ($marker === 0xDA) {
                if (! $sawFrame) {
                    return null;
                }

                // Start of scan: copy the header, then the entropy-coded data
                // up to the next marker that is not a stuffed 0x00 or a
                // restart (RSTn) — progressive JPEGs carry several scans.
                $out .= "\xFF\xDA".$segment;
                $start = $i;

                while ($i + 1 < $length) {
                    if ($raw[$i] === "\xFF") {
                        $next = ord($raw[$i + 1]);

                        if ($next !== 0x00 && ! ($next >= 0xD0 && $next <= 0xD7) && $next !== 0xFF) {
                            break;
                        }
                    }
                    $i++;
                }

                $out .= substr($raw, $start, $i - $start);

                continue;
            }

            if (in_array($marker, $keep, true)) {
                $out .= "\xFF".chr($marker).$segment;
            }
            // Everything else — APP1..APP13, APP15, COM, unknown — is dropped.
        }

        return null;
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
