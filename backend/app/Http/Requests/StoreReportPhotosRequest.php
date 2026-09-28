<?php

namespace App\Http\Requests;

use App\Models\DailySiteReport;
use App\Models\SiteActivityReport;
use App\Rules\ImageContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/{site-activity-reports|daily-site-reports}/{report}/photos
 *
 * One request for both report types because the question is identical:
 * "is this a photograph, and may you attach one here?". The route model it
 * asks about differs, so `authorize()` resolves whichever the URL named and
 * puts the same `update` question to that report's policy — which is also
 * what refuses a photograph on an already-submitted report, since `update`
 * is false for one.
 *
 * Photographs arrive one batch at a time rather than inside the create or
 * update payload. That is a connectivity decision, not an API-fashion one:
 * a report saved with three 4 MB photos re-uploads all three on every
 * correction, and a site with one bar of signal would never finish an edit.
 * `POST .../photos` lets the document be saved first and the evidence
 * follow.
 *
 * Four checks before anything is written — declared extension, sniffed MIME,
 * size, and whether the bytes actually decode (`ImageContent`, which parses
 * the header for dimensions without allocating a pixel buffer). The fifth
 * and last is `SelfieSanitizer` inside ReportPhotoStore, which runs for
 * every client including the ones that skip this request.
 *
 * `caption` is a single optional string applied to every photograph in the
 * batch — one caption for "these three are the rebar before the pour" —
 * rather than a parallel array the client has to keep in step.
 */
class StoreReportPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('siteActivityReport') ?? $this->route('dailySiteReport');

        if ($report instanceof SiteActivityReport || $report instanceof DailySiteReport) {
            return $this->user()?->can('update', $report) ?? false;
        }

        return false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) config('hrms.storage.report_photo_max_kilobytes', 5120);

        return [
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$maxKilobytes,
                new ImageContent,
            ],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
