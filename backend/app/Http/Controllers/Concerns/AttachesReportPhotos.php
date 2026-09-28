<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\StoreReportPhotosRequest;
use App\Models\DailySiteReport;
use App\Models\DailySiteReportPhoto;
use App\Models\SiteActivityReport;
use App\Models\SiteActivityReportPhoto;
use App\Services\SiteReport\ReportPhotoStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Adding and removing photographs, for both site-report controllers.
 *
 * Deliberately a trait rather than a base controller: neither report
 * controller needs anything else this code does, and a shared parent that
 * exists only to hold two methods is a hierarchy somebody will later want
 * to widen. What is *shared* is here because the two upload paths must
 * behave identically — same per-report ceiling, same partial-failure
 * unwind, same 409 for a submitted record, same 404 for a photograph that
 * belongs to a different report — and three copies of "delete the files we
 * already wrote" is three places to forget one.
 *
 * The controller supplies its own ReportPhotoStore through
 * {@see self::reportPhotoStore()} rather than the trait reaching into a
 * property, so the dependency stays declared on the constructor where it
 * can be seen and replaced in a test.
 */
trait AttachesReportPhotos
{
    abstract protected function reportPhotoStore(): ReportPhotoStore;

    /**
     * Sanitise and store each upload, then write the rows in one
     * transaction — and unwind the files if any of it fails, so a rejected
     * fifth photograph does not leave four orphaned bytes on disk attached
     * to a report that shows none.
     *
     * @param  int  $maxPerReport  the total one report may hold
     * @return array<int, SiteActivityReportPhoto|DailySiteReportPhoto>
     */
    protected function attachReportPhotos(
        StoreReportPhotosRequest $request,
        SiteActivityReport|DailySiteReport $report,
        int $maxPerReport,
    ): array {
        $store = $this->reportPhotoStore();

        $files = array_values($request->file('photos') ?? []);
        $existing = $report->photos()->count();

        if ($existing + count($files) > $maxPerReport) {
            throw ValidationException::withMessages([
                'photos' => sprintf(
                    'A report can hold at most %d photographs; %d are already attached.',
                    $maxPerReport,
                    $existing,
                ),
            ]);
        }

        $caption = $request->filled('caption') ? (string) $request->input('caption') : null;

        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $store->store($file, $report);
            }
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                $store->delete($path);
            }

            throw $exception;
        }

        return DB::transaction(function () use ($report, $stored, $existing, $caption) {
            $records = [];

            foreach ($stored as $index => $path) {
                $records[] = $report->photos()->create([
                    'path' => $path,
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => (int) Storage::disk('local')->size($path),
                    'caption' => $caption,
                    'sort_order' => $existing + $index,
                ]);
            }

            return $records;
        });
    }

    /**
     * The row and the photograph in the URL must belong to each other.
     *
     * Implicit binding resolves both ids independently, so without this a
     * caller could name their own report and somebody else's photograph
     * id — and a `DELETE` would delete it. 404 rather than 403: the
     * photograph's existence is not a fact this caller is entitled to.
     */
    protected function assertPhotoBelongsTo(
        SiteActivityReport|DailySiteReport $report,
        SiteActivityReportPhoto|DailySiteReportPhoto $photo,
    ): void {
        $belongs = match (true) {
            $report instanceof SiteActivityReport => $photo instanceof SiteActivityReportPhoto
                && $photo->site_activity_report_id === $report->getKey(),
            $report instanceof DailySiteReport => $photo instanceof DailySiteReportPhoto
                && $photo->daily_site_report_id === $report->getKey(),
        };

        if (! $belongs) {
            abort(404, 'That photograph is not part of this report.');
        }
    }

    /**
     * 409 rather than 403: the account is authorized to change photographs,
     * the *record* is past the point where they may change. Saying "you may
     * not" would be untrue and would send this person to the permission
     * screen when the answer is "submit a draft".
     */
    protected function assertReportIsEditable(SiteActivityReport|DailySiteReport $report): void
    {
        if (! $report->isEditable()) {
            abort(409, 'Photographs can only be changed on a draft report.');
        }
    }
}
