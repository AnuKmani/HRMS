<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Refuse an upload whose bytes are neither a PDF nor an image.
 *
 * `mimes` and `mimetypes` read the file's *name* (and, for real uploads, its
 * header-sniffed type), so a 3 KB of garbage renamed `note.pdf` sails past
 * both and is then handed to whoever opens it next. This rule looks at the
 * content itself:
 *
 *  - a PDF must begin with `%PDF-` inside its first 1024 bytes — the spec
 *    allows leading junk, so the signature is searched for rather than
 *    demanded at offset 0;
 *  - an image must survive `getimagesize()`, which parses the header far
 *    enough to report dimensions and type without decoding a single frame.
 *
 * Deliberately *not* a full decode. There is no re-encoding step for
 * certificates — unlike a selfie, a doctor's note has to stay exactly the
 * document it was — so nothing here needs the pixel budget or the flattening
 * that ImageContent performs. Reading the header is enough to tell a document
 * from a payload, and it is a check that works identically in tests (where
 * `Illuminate\Http\Testing\File` fakes the declared type) and in production.
 *
 * @see  SickCertificateStore for where the file is written and how it is read back
 */
class CertificateContent implements ValidationRule
{
    /** Only the beginning of the file is read — a PDF's signature is early. */
    private const PROBE_BYTES = 1024;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be a file.');

            return;
        }

        if (! $value->isValid()) {
            $fail('The :attribute did not upload successfully.');

            return;
        }

        $path = $value->getRealPath();

        if ($path === null) {
            $fail('The :attribute could not be read.');

            return;
        }

        $probe = @file_get_contents($path, false, null, 0, self::PROBE_BYTES);

        if ($probe === false || $probe === '') {
            $fail('The :attribute could not be read.');

            return;
        }

        if (str_contains($probe, '%PDF-')) {
            return;
        }

        if (@getimagesize($path) !== false) {
            return;
        }

        $fail('The :attribute is not a PDF or an image a reader could open.');
    }
}
