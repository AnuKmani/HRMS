<?php

namespace App\Services\SiteReport;

use App\Models\DailySiteReport;
use App\Models\SiteActivityReport;
use App\Services\Attendance\SelfieSanitizer;
use App\Services\Images\StoresPrivateImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where site-report photographs live, and how they are read back.
 *
 * The same four properties attendance selfies have (private, unnameable,
 * unexposed, sanitised) hold here for the same reasons, and — importantly —
 * for the same *code*: `StoresPrivateImages` is the shared accept / contain
 * / serve implementation, and the bytes come from the same injected
 * `SelfieSanitizer`, which decodes and re-encodes so no EXIF block, no GPS
 * fix, no camera model and no client filename survives to disk. A site
 * photograph is exactly as good a place to hide a location trail as a
 * selfie is, and it would be an odd boundary that only guarded one of them.
 *
 * What differs is the layout: a selfie is `{employee}/{uuid}.jpg` because
 * one person's check-ins belong together, while a report photograph is
 * `{kind}/{reportId}/{uuid}.jpg` because a document's photographs belong to
 * the document and follow it into any export that later reads the
 * directory. `store()` derives that folder from the model it is handed —
 * never from a string a caller passes in — so no upload can be written
 * outside the two directories this module owns.
 *
 * Nothing here returns a path to a request. `SiteActivityReportPhotoResource`
 * and `DailySiteReportPhotoResource` expose an id and a caption; the bytes
 * are reachable only through `GET .../{report}/photos/{photo}`, which asks
 * the report's policy first, and the response carries `no-store` so a
 * shared tablet's cache does not hand the photograph to the next person who
 * picks it up.
 *
 * Rejection happens twice, as it does for selfies: `Store*PhotosRequest`
 * answers the cheap questions with a 422 that names the field, and
 * `isAcceptable()` here asks them again before a byte is decoded.
 */
final class ReportPhotoStore
{
    use StoresPrivateImages;

    public function __construct(private readonly SelfieSanitizer $sanitizer) {}

    /**
     * Sanitize and store one upload, and return the private path it
     * landed on. The path is for the row to hold and never for a response.
     */
    public function store(UploadedFile $file, SiteActivityReport|DailySiteReport $report): string
    {
        if (! $this->isAcceptable($file)) {
            abort(422, 'That file is not an accepted image. Please take another photo.');
        }

        $bytes = $this->sanitise($file);

        $path = sprintf(
            '%s/%s/%s.%s',
            $this->directory(),
            $this->folder($report),
            Str::uuid()->toString(),
            SelfieSanitizer::OUTPUT_EXTENSION,
        );

        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    public function directory(): string
    {
        return trim((string) config('hrms.storage.report_photo_directory', 'site-report-photos'), '/');
    }

    public function maxKilobytes(): int
    {
        return (int) config('hrms.storage.report_photo_max_kilobytes', 5120);
    }

    /**
     * `activity/14` or `daily/6` — derived from the class, so a caller
     * cannot ask for a folder this module does not own.
     */
    private function folder(SiteActivityReport|DailySiteReport $report): string
    {
        $kind = match (true) {
            $report instanceof SiteActivityReport => 'activity',
            $report instanceof DailySiteReport => 'daily',
        };

        return $kind.'/'.$report->getKey();
    }

    /**
     * The pixel budget, asked before the sanitizer is — in this module's
     * words rather than attendance's.
     *
     * `SelfieSanitizer` is shared deliberately: one decoder, one encoder,
     * one set of budgets, one place that proves EXIF and GPS cannot survive
     * a re-encode. What is *not* shared is its vocabulary — its refusals say
     * "selfie" because they were written for check-in, and a person
     * attaching a photograph of a shuttering is not being asked to take a
     * new selfie. A message naming the wrong subject sends somebody looking
     * in the wrong place.
     *
     * The check here is the sanitizer's own `probe()` + `fitsBudget()`, so
     * there are not two budgets to keep in step — only the sentence is ours.
     * The remaining refusals inside `sanitize()` are decode and encode
     * failures, which `ImageContent` has already made unreachable for any
     * file that reaches here; should one fire anyway, the 422 it raises is
     * still a 422 and still refuses the upload.
     */
    private function sanitise(UploadedFile $file): string
    {
        $size = $this->sanitizer->probe($file);

        if ($size === null || ! $this->sanitizer->fitsBudget($size)) {
            abort(422, 'That photo is too large to process. Please take a smaller one.');
        }

        return $this->sanitizer->sanitize($file);
    }
}
