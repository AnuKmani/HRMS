<?php

namespace App\Rules;

use App\Services\Attendance\SelfieSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * "Is that actually a photograph, and is it one we are willing to decode?"
 *
 * The other selfie rules answer cheaper questions: what the extension
 * claims, what the MIME sniff claims. Both of those are satisfied by a file
 * no decoder would look at twice — a PDF renamed to `.jpg`, a text file
 * with a JPEG header glued on — because both are answered from a header.
 * This rule parses the header itself and reads the dimensions, which is the
 * last question that can be answered without allocating a pixel buffer.
 *
 * It lives in the request rather than only in `SelfieStore` because a
 * person who chose a bad photograph deserves to be told *which* photograph
 * and why, on the field, before a transaction opens. `SelfieStore` asks the
 * same questions again anyway: a validation layer and a storage layer that
 * trust each other completely are one refactor away from trusting nobody.
 *
 * The sanitizer is constructed directly and deliberately — it is stateless
 * (its only inputs are `config()`), and a rule object has no container to
 * be injected into.
 */
class ImageContent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sanitizer = new SelfieSanitizer;

        if (! $value instanceof UploadedFile) {
            $fail('That file is not a readable image.');

            return;
        }

        $size = $sanitizer->probe($value);

        if ($size === null) {
            $fail('That file is not a readable image.');

            return;
        }

        if (! $sanitizer->fitsBudget($size)) {
            $fail('That image is too large to process. Take a smaller one.');
        }
    }
}
