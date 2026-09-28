<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AttachesReportPhotos;
use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDailySiteReportRequest;
use App\Http\Requests\StoreReportPhotosRequest;
use App\Http\Requests\SubmitDailySiteReportRequest;
use App\Http\Requests\UpdateDailySiteReportRequest;
use App\Http\Resources\DailySiteReportPhotoResource;
use App\Http\Resources\DailySiteReportResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\DailySiteReport;
use App\Models\DailySiteReportPhoto;
use App\Services\SiteReport\DailySiteReportPdf;
use App\Services\SiteReport\DailySiteReportService;
use App\Services\SiteReport\ReportPhotoStore;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/daily-site-reports
 *
 * The official record of a site-day: one row per site per date, written by
 * whoever prepares it, read by whoever their permission and the row-level
 * policy allow.
 *
 * Two things distinguish this from the activity controller:
 *
 *  - **`pdf`**. Generated on demand and streamed inline — see
 *    DailySiteReportPdf for why nothing is ever stored — behind
 *    `daily_site_reports.pdf` *and* `DailySiteReportPolicy::pdf()`, because
 *    a permission that only grants documents for reports you could already
 *    read is the whole point of separating the two.
 *  - **no delete**. A site-day that has a report is a site-day somebody may
 *    already have read. Corrections happen on drafts, in place, by the
 *    author.
 *
 * `approve` does not exist. `approved_at` is a column reserved for a later
 * phase, and shipping an endpoint that writes it without a workflow behind
 * it would make it a button anybody with `update` could press.
 */
class DailySiteReportController extends Controller
{
    use AttachesReportPhotos;
    use BuildsResourceLists;

    public const MAX_PHOTOS_PER_REPORT = 12;

    public function __construct(
        private readonly DailySiteReportService $service,
        private readonly ReportPhotoStore $photos,
        private readonly DailySiteReportPdf $pdf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DailySiteReport::class);

        $query = Visibility::dailySiteReportsFor(
            DailySiteReport::query()->with(['creator', 'project', 'site']),
            $request->user(),
        );

        if ($projectId = $this->param($request, 'project_id')) {
            $query->where('project_id', (int) $projectId);
        }

        if ($siteId = $this->param($request, 'site_id')) {
            $query->where('site_id', (int) $siteId);
        }

        // "Who wrote it" — a filter, not an override. The visibility scope
        // already ran, so asking for another author's id narrows to nothing
        // rather than reaching through the scope.
        if ($createdBy = $this->param($request, 'created_by', 'creator_id')) {
            $query->where('created_by', $createdBy);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($from = $this->param($request, 'from', 'date_from')) {
            $query->where('report_date', '>=', $from);
        }

        if ($to = $this->param($request, 'to', 'date_to')) {
            $query->where('report_date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->where(function ($q) use ($escaped) {
                $q->where('work_completed', 'like', $escaped)
                    ->orWhere('work_planned', 'like', $escaped);
            });
        }

        $page = $query->orderBy(
            $this->sortColumn(
                $request,
                ['report_date', 'total_manpower', 'status', 'created_at'],
                'report_date',
            ),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Daily site reports retrieved.',
            DailySiteReportResource::collection($page->getCollection()),
            $page,
        );
    }

    public function store(StoreDailySiteReportRequest $request): JsonResponse
    {
        $report = $this->service->create($request->user(), $request->validated());

        return ApiResponse::created(
            'Daily site report created.',
            new DailySiteReportResource($report->load($this->eager())),
        );
    }

    public function show(Request $request, DailySiteReport $dailySiteReport): JsonResponse
    {
        $this->authorize('view', $dailySiteReport);

        $dailySiteReport->load($this->eager());

        return ApiResponse::success(
            'Daily site report retrieved.',
            new DailySiteReportResource($dailySiteReport),
        );
    }

    public function update(
        UpdateDailySiteReportRequest $request,
        DailySiteReport $dailySiteReport,
    ): JsonResponse {
        $report = $this->service->update(
            $request->user(),
            $dailySiteReport,
            $request->validated(),
        );

        return ApiResponse::success(
            'Daily site report updated.',
            new DailySiteReportResource($report->load($this->eager())),
        );
    }

    public function submit(
        SubmitDailySiteReportRequest $request,
        DailySiteReport $dailySiteReport,
    ): JsonResponse {
        $report = $this->service->submit($request->user(), $dailySiteReport);

        return ApiResponse::success(
            'Daily site report submitted.',
            new DailySiteReportResource($report->load($this->eager())),
        );
    }

    public function storePhotos(
        StoreReportPhotosRequest $request,
        DailySiteReport $dailySiteReport,
    ): JsonResponse {
        $this->assertReportIsEditable($dailySiteReport);

        $records = $this->attachReportPhotos(
            $request,
            $dailySiteReport,
            self::MAX_PHOTOS_PER_REPORT,
        );

        return ApiResponse::created(
            'Photographs added.',
            DailySiteReportPhotoResource::collection($records),
        );
    }

    public function destroyPhoto(
        Request $request,
        DailySiteReport $dailySiteReport,
        DailySiteReportPhoto $photo,
    ): JsonResponse {
        $this->authorize('update', $dailySiteReport);

        $this->assertPhotoBelongsTo($dailySiteReport, $photo);
        $this->assertReportIsEditable($dailySiteReport);

        $this->photos->delete($photo->path);
        $photo->delete();

        return ApiResponse::success('Photograph removed.');
    }

    public function showPhoto(
        Request $request,
        DailySiteReport $dailySiteReport,
        DailySiteReportPhoto $photo,
    ): StreamedResponse {
        $this->authorize('view', $dailySiteReport);

        $this->assertPhotoBelongsTo($dailySiteReport, $photo);

        $response = $this->photos->response($photo->path);

        if ($response === null) {
            abort(404, 'That photograph is no longer available.');
        }

        return $response;
    }

    /**
     * Build the document now, and hand it straight back.
     *
     * No cache header, no stored file, no URL to guess: the response
     * carries `no-store` so a shared tablet's proxy does not keep a copy
     * after the tablet is handed to the next person, and `Cache-Control`
     * is the only thing standing between "regenerated every time" in
     * DailySiteReportPdf and it actually being true.
     */
    public function pdf(Request $request, DailySiteReport $dailySiteReport): Response
    {
        $this->authorize('pdf', $dailySiteReport);

        // Loaded here rather than left for the generator to chase one
        // relation at a time: seven lazy queries per document is seven
        // queries nobody budgeted for on an endpoint that is already doing
        // the most expensive thing the API does.
        $dailySiteReport->load($this->eager());

        return $this->pdf->response($dailySiteReport);
    }

    /**
     * @return array<int, string>
     */
    private function eager(): array
    {
        return [
            'creator',
            'project',
            'site',
            'manpower',
            'materials',
            'equipment',
            'photos',
        ];
    }

    protected function reportPhotoStore(): ReportPhotoStore
    {
        return $this->photos;
    }
}
