<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportExportResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\ReportExport;
use App\Services\Reporting\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/report-exports — what you asked for, and the file when it is
 * ready.
 *
 * Both endpoints are scoped to the caller by `user_id` before anything is
 * counted or streamed. That is the whole authorisation: an export is a
 * personal artefact built from a query re-authorised under *your*
 * permissions at build time, so there is no role check here beyond "is
 * this your row?" — and "not yours" answers 404 rather than 403, because
 * from outside, "does not exist" and "is not yours" must look identical
 * or the id sequence becomes a probe for how many people have exported
 * payroll this week.
 *
 * The download route is the only reader of `report_exports.path`. The
 * column is hidden on the model, absent from the resource, and the file
 * sits under `exports/{userId}/{uuid}.{ext}` on a disk with no public
 * URL — four independent things standing between a guessed id and a
 * spreadsheet, which is roughly the right number for a payroll register.
 */
class ReportExportController extends Controller
{
    public function __construct(private readonly ReportExporter $exporter) {}

    /**
     * GET /api/v1/report-exports
     */
    public function index(Request $request): JsonResponse
    {
        $page = ReportExport::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Your exports.',
            ReportExportResource::collection($page),
            $page,
        );
    }

    /**
     * Respects the application-wide per-page config with a sane default.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', config('hrms.pagination.per_page', 15));

        return max(1, min($requested, 100));
    }

    /**
     * GET /api/v1/report-exports/{reportExport}/file
     *
     * Three answers before a byte moves: yours, `ready`, and still on
     * disk. The third one is real — an operator sweeping `storage/app/
     * private/exports` is a reasonable thing to do on a box that has been
     * running for a year, and "your file expired" is a better message
     * than a 500 from a missing path.
     */
    public function file(Request $request, ReportExport $reportExport): JsonResponse|StreamedResponse
    {
        if ((int) $reportExport->user_id !== (int) $request->user()->id) {
            abort(404, 'Export not found.');
        }

        if ($reportExport->status !== ReportExport::STATUS_READY) {
            return ApiResponse::error(
                $reportExport->status === ReportExport::STATUS_FAILED
                    ? 'This export failed: '.($reportExport->error ?? 'no reason recorded.')
                    : 'This export is still being built. Try again in a moment.',
                null,
                409,
            );
        }

        if ($reportExport->path === null || ! Storage::disk('local')->exists($reportExport->path)) {
            abort(404, 'This export has expired and is no longer available.');
        }

        return $this->exporter->downloadStored($reportExport->path, $this->filename($reportExport));
    }

    /**
     * The name it is saved under — the report key and when it was made,
     * which is what a person scrolling a Downloads folder is looking for.
     * The stored path's UUID is deliberately *not* used: it identifies
     * nothing to a human and would only teach people that exports are
     * named after random strings.
     */
    private function filename(ReportExport $export): string
    {
        $when = $export->created_at?->format('Ymd-His') ?? now()->format('Ymd-His');

        return $export->report_key.'-'.$when.'.'.$export->format;
    }
}
