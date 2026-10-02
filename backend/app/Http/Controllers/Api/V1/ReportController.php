<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportExportResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\BuildReportExport;
use App\Models\ReportExport;
use App\Services\Reporting\ReportDefinition;
use App\Services\Reporting\ReportExporter;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\ReportRegistry;
use App\Services\Reporting\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/reports — the catalogue, one report's rows, and both ways of
 * exporting one.
 *
 * Two gates, as short a description of this controller as any: the route's
 * `permission:reports.view` proves the account may open the reporting
 * screen, and `ReportService::definition()` re-asks the per-report
 * permission stored in the registry *after* the key has been read from
 * the path. A key typed into a URL therefore cannot reach a report its
 * role does not hold — the route gate alone would answer for every key in
 * the catalogue, which is one gate too few.
 *
 * `index` lists only what the caller may run rather than all thirteen
 * with a permission attached: a catalogue of reports you cannot open is a
 * map of what the company has, and the two remaining numbers (how many
 * rows, which filters) are answered by `show` and by `supported_filters`
 * once you are inside one.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportExporter $exporter,
    ) {}

    /**
     * GET /api/v1/reports
     */
    public function index(Request $request): JsonResponse
    {
        $items = array_map(
            fn ($definition): array => $this->describe($definition),
            ReportRegistry::visible($request->user()),
        );

        // Same shape as every other list — items plus the four pager
        // numbers — even though this one cannot paginate. A client that
        // renders lists from `{items, meta}` should not need a special
        // case for the endpoint that happens to be a fixed thirteen long.
        return ApiResponse::success('Reports available to you.', (object) [
            'items' => $items,
            'meta' => (object) [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => count($items),
                'total' => count($items),
                'has_next' => false,
            ],
            'filters' => ReportFilters::ALL,
        ]);
    }

    /**
     * GET /api/v1/reports/{key}
     */
    public function show(Request $request, string $key): JsonResponse
    {
        $definition = $this->reports->definition($key, $request->user());
        $filters = $this->reports->filters($definition, ReportFilters::read($request));

        $page = max(1, (int) $request->query('page', 1));
        $perPage = $this->perPage($request);

        $rows = $this->reports->rows($definition, $filters, $page, $perPage);

        return ApiResponse::success($definition->title, (object) [
            'report' => $this->describe($definition),
            'filters' => (object) $filters,
            'filter_description' => ReportFilters::describe($filters),
            'items' => $rows->items(),
            'meta' => (object) [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'has_next' => $rows->hasMorePages(),
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/{key}/export?format=csv|xlsx|pdf
     *
     * The small, immediate one. Over `hrms.reporting.export_max_rows` rows
     * it refuses and names the queued route instead — a 6,000-row XLSX
     * built inside a request holds a worker and a connection for the best
     * part of a minute, and that request is one a load balancer will cut
     * in half, leaving the user with a file that opens and stops.
     *
     * The refusal is a 422, not a 403 and not a silent truncation:
     * nothing is wrong with the caller's permission and nothing is wrong
     * with the request's *shape* — the report is simply too big to
     * deliver this way, and the message says which way will work.
     */
    public function export(Request $request, string $key): StreamedResponse
    {
        $definition = $this->reports->definition($key, $request->user());
        $filters = $this->reports->filters($definition, ReportFilters::read($request));
        $format = $this->format($request);

        $rows = $this->reports->count($definition, $filters);
        $max = (int) config('hrms.reporting.export_max_rows');

        if ($rows > $max) {
            throw ValidationException::withMessages([
                'format' => sprintf(
                    '%s rows is over the %d-row limit for a download. POST /api/v1/reports/%s/exports to have it built in the background, then fetch it from /api/v1/report-exports.',
                    number_format($rows),
                    $max,
                    $definition->key,
                ),
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'hrms-report-');

        if ($path === false) {
            abort(500, 'Could not prepare a temporary file for the export.');
        }

        try {
            $this->exporter->exportToFile(
                $definition,
                $filters,
                $format,
                $path,
                $request->user(),
                $max,
            );
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return $this->exporter->download($path, $this->filename($definition, $filters, $format));
    }

    /**
     * POST /api/v1/reports/{key}/exports
     *
     * The large one. It parks a `report_exports` row, hands the build to a
     * worker and answers 202 — the row's `filters` are re-read and
     * re-authorised by the job, so what gets built is what this account is
     * still allowed to see *when it is built*, not merely when it was
     * asked for.
     */
    public function storeExport(Request $request, string $key): JsonResponse
    {
        $definition = $this->reports->definition($key, $request->user());
        $filters = $this->reports->filters($definition, ReportFilters::read($request));
        $format = $this->format($request);

        $rows = $this->reports->count($definition, $filters);

        // PDF is refused at the door rather than in the job: a job that
        // fails before it starts is a `failed` row the user has to
        // discover, and this is a message they can act on right now.
        $max = $format === ReportExport::FORMAT_PDF
            ? (int) config('hrms.reporting.export_max_rows')
            : (int) config('hrms.reporting.queue_max_rows');

        if ($rows > $max) {
            throw ValidationException::withMessages([
                'format' => sprintf(
                    '%s rows is over the %d-row limit for a %s export. Narrow the filters — a date range is usually enough.',
                    number_format($rows),
                    $max,
                    $format,
                ),
            ]);
        }

        $export = ReportExport::query()->create([
            'user_id' => $request->user()->id,
            'report_key' => $definition->key,
            'format' => $format,
            'filters' => (object) $filters,
            'status' => ReportExport::STATUS_PENDING,
            // Minted now and stored: the job writes to *this* path, so a
            // retry lands on the same file rather than leaving three
            // abandoned attempts behind it.
            'path' => $this->exporter->pathFor($request->user(), $format),
            'error' => null,
        ]);

        BuildReportExport::dispatch($export->id)
            ->onQueue((string) config('hrms.reporting.queue'));

        return ApiResponse::created(
            'Export queued. It will appear in your exports when it is ready.',
            new ReportExportResource($export),
        );
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Catalogue entry: identity, columns, and which of the six filters
     * this report can actually answer. The client renders its controls
     * from this rather than from a hard-coded list, which is what stops
     * a site filter appearing on the asset register where there is no
     * site to filter on.
     */
    private function describe(ReportDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'title' => $definition->title,
            'description' => $definition->description,
            'permission' => $definition->permission,
            'supported_filters' => $definition->supportedFilters(),
            'columns' => $definition->columns,
            'formats' => ReportExport::FORMATS,
        ];
    }

    private function format(Request $request): string
    {
        $requested = strtolower((string) $request->query('format', $request->input('format', 'csv')));

        if (! in_array($requested, ReportExport::FORMATS, true)) {
            throw ValidationException::withMessages([
                'format' => 'Unknown export format. Use csv, xlsx or pdf.',
            ]);
        }

        return $requested;
    }

    /**
     * A name a person can find again in Downloads a week later: the report
     * key (so two different reports never share one) and the window it
     * covers, or the time it was made when there is no window. No user id
     * and no path — the file is already in a directory named after them.
     *
     * @param  array<string, string>  $filters
     */
    private function filename(ReportDefinition $definition, array $filters, string $format): string
    {
        $from = $filters['from'] ?? '';
        $to = $filters['to'] ?? '';

        $window = $from.($from && $to ? '_to_' : '').$to;

        if ($window === '') {
            $window = now()->format('Ymd-His');
        }

        $name = $definition->key.'-'.$window;

        // Both halves matter: a slug keeps it a filename on every
        // filesystem, the extension keeps it opening in the right program.
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $name).'.'.$format;
    }

    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', config('hrms.reporting.page_size'));

        // 500, not the 100 the CRUD lists use: a report is read as a
        // table, and paging a 13-row result four times is a control the
        // user has to press rather than a safety property — the row cap
        // that matters is the export's.
        return max(1, min($requested, 500));
    }
}
