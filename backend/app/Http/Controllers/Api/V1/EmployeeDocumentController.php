<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectEmployeeDocumentRequest;
use App\Http\Requests\StoreEmployeeDocumentRequest;
use App\Http\Requests\UpdateEmployeeDocumentRequest;
use App\Http\Resources\EmployeeDocumentResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\EmployeeDocument;
use App\Services\Documents\EmployeeDocumentService;
use App\Services\Documents\EmployeeDocumentStore;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/employee-documents
 *
 * The only controller that writes an employment document, and it writes
 * none of it. Every transition goes through EmployeeDocumentService, so
 * "which sequence can turn a pending upload into a verified one, and what
 * happens to the signature when the file changes" has exactly one answer —
 * this class decides *who may ask*, the service decides *whether the ask is
 * legal*.
 *
 * Authorisation is split the way leave, overtime and expenses split it:
 *
 *   - the coarse `permission:` middleware on each route is the door;
 *   - EmployeeDocumentPolicy is the row: is this file yours, may you sign
 *     off on it, is it your own (never self-verified);
 *   - the service is the state machine, and it answers 409 naming the state
 *     rather than 403, because "already verified" and "you are not allowed"
 *     are different sentences and only one of them tells the caller what to
 *     do next.
 *
 * The row scope is Visibility's, asked here for the list and in the policy
 * for the single record — the collection is the easier endpoint to hit, so
 * a policy that refused one record while the index returned the other would
 * be no boundary at all.
 */
class EmployeeDocumentController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly EmployeeDocumentService $service,
        private readonly EmployeeDocumentStore $files,
    ) {}

    /**
     * GET /api/v1/employee-documents
     *
     * Nine filters, all of them answers a desk actually asks for:
     * whose file, what kind, what state, when it expires, has it already
     * lapsed, is it about to.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeDocument::class);

        $query = EmployeeDocument::query()
            ->with(['documentType', 'employee'])
            ->select('employee_documents.*');

        // Joined unconditionally rather than when a filter needs it: it is
        // a many-to-one, so it can never duplicate a row, and having one
        // alias in play is what keeps `expiring_soon` from colliding with
        // `document_type_id` the first time both are asked for.
        $query->join('document_types', 'document_types.id', '=', 'employee_documents.document_type_id');

        $query = Visibility::employeeDocumentsFor($query, $request->user());

        $this->applyFilters($query, $request);

        $query->orderBy(
            $this->sortColumn($request, [
                'created_at', 'updated_at', 'expiry_date', 'status', 'document_number',
            ], 'created_at'),
            $this->sortDirection($request, 'desc'),
        );

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Employee documents.',
            EmployeeDocumentResource::collection($page),
            $page,
        );
    }

    /**
     * POST /api/v1/employee-documents
     *
     * The FormRequest already decided *who for* (your own with
     * `documents.create`, anybody's with `documents.manage`) before a byte
     * of the payload was read; what is left here is handing the payload to
     * the one class allowed to write it.
     */
    public function store(StoreEmployeeDocumentRequest $request): JsonResponse
    {
        $employee = $request->target();

        abort_unless($employee !== null, 403, 'You may not file a document for that employee.');

        $document = $this->service->create(
            $request->user(),
            $employee,
            $request->documentPayload(),
        );

        $document->load(['documentType', 'employee']);

        return ApiResponse::created('Document uploaded.', new EmployeeDocumentResource($document));
    }

    /**
     * GET /api/v1/employee-documents/{document}
     */
    public function show(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->load(['documentType', 'employee']);

        return ApiResponse::success('Employee document.', new EmployeeDocumentResource($document));
    }

    /**
     * PUT /api/v1/employee-documents/{document}
     */
    public function update(UpdateEmployeeDocumentRequest $request, EmployeeDocument $document): JsonResponse
    {
        $employee = $document->employee;

        $document = $this->service->update(
            $request->user(),
            $employee,
            $document,
            $request->validated(),
        );

        $document->load(['documentType', 'employee']);

        return ApiResponse::success('Document updated.', new EmployeeDocumentResource($document));
    }

    /**
     * DELETE /api/v1/employee-documents/{document}
     *
     * **Archives. Nothing is removed.** The row stays, the file stays, and
     * `archived_at` records when it left the active list — because an
     * employment file is the record of a person who worked here, and
     * losing it because somebody clicked a trash icon is exactly the
     * "physical removal of something with historical importance" the
     * specification rules out. A terminal state that could be walked back
     * out of would make the archive a filter rather than a decision, so
     * there is no un-archive route either: an operator with database access
     * has that door, and the API deliberately does not.
     */
    public function destroy(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $document = $this->service->archive($request->user(), $document);

        return ApiResponse::success('Document archived.', new EmployeeDocumentResource($document));
    }

    /**
     * GET /api/v1/employee-documents/{document}/file
     *
     * The one way to the bytes. Same gate as the row it belongs to —
     * your own, or `documents.manage` — because the file *is* the
     * disclosure: gating it a step further would protect the scan while
     * leaving the passport number readable beside it.
     */
    public function file(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $this->authorize('viewFile', $document);

        $response = $this->files->response($document->path, $document->original_name);

        abort_if($response === null, 404, 'That document has no file attached.');

        return $response;
    }

    /**
     * POST /api/v1/employee-documents/{document}/verify
     */
    public function verify(Request $request, EmployeeDocument $document): JsonResponse
    {
        $this->authorize('verify', $document);

        $document = $this->service->verify($request->user(), $document);
        $document->load(['documentType', 'employee']);

        return ApiResponse::success('Document verified.', new EmployeeDocumentResource($document));
    }

    /**
     * POST /api/v1/employee-documents/{document}/reject
     *
     * Authorised inside the request, which is where `reason` arrives: the
     * policy condition (`documents.verify`, readable, not your own) and the
     * payload condition ("say why") belong on the same screen.
     */
    public function reject(RejectEmployeeDocumentRequest $request, EmployeeDocument $document): JsonResponse
    {
        $document = $this->service->reject(
            $request->user(),
            $document,
            (string) $request->validated('reason'),
        );

        $document->load(['documentType', 'employee']);

        return ApiResponse::success('Document rejected.', new EmployeeDocumentResource($document));
    }

    /**
     * GET /api/v1/employee-documents/expiring
     *
     * The cross-employee "what is about to lapse" view, behind its own
     * permission because the question is about everybody rather than about
     * you. Rows are still scoped by Visibility, so a holder of the report
     * permission without `documents.manage` sees their own file and nobody
     * else's — the permission opens the door, it does not hand over the
     * room.
     */
    public function expiring(Request $request): JsonResponse
    {
        $this->authorize('expiryReport', EmployeeDocument::class);

        // `within` widens the window from "already gone" to "soon", in days,
        // capped so one request cannot ask for the whole future. Built as a
        // single comparison rather than `<= today OR <= today+within`,
        // because an OR appended *after* the row scope has been applied
        // would escape it — the one line that would quietly turn this into
        // everybody's expiry report for anybody holding the permission.
        $within = (int) min(max((int) $request->query('within', 0), 0), 730);
        $until = $within > 0 ? now()->addDays($within)->toDateString() : now()->toDateString();

        $query = EmployeeDocument::query()
            ->with(['documentType', 'employee'])
            ->select('employee_documents.*')
            ->join('document_types', 'document_types.id', '=', 'employee_documents.document_type_id')
            ->whereNotNull('employee_documents.expiry_date')
            ->where('employee_documents.expiry_date', '<=', $until)
            ->where('employee_documents.status', '!=', EmployeeDocument::STATUS_ARCHIVED)
            ->orderBy('employee_documents.expiry_date')
            ->orderBy('employee_documents.id');

        $query = Visibility::employeeDocumentsFor($query, $request->user());

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Documents due to expire.',
            EmployeeDocumentResource::collection($page),
            $page,
        );
    }

    /**
     * @param  Builder<EmployeeDocument>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($id = $this->param($request, 'employee_id')) {
            $query->where('employee_documents.employee_id', (int) $id);
        }

        if ($id = $this->param($request, 'document_type_id')) {
            $query->where('employee_documents.document_type_id', (int) $id);
        }

        $status = $this->param($request, 'status');

        if ($status === null) {
            // Archived rows stay in the database and leave the list. With no
            // `status` named, the default answer is "what is in circulation"
            // — asking for `status=archived` is how an operator goes and
            // looks at what was taken out, and no `DELETE` route exists to
            // take anything back out of existence.
            $query->where('employee_documents.status', '!=', EmployeeDocument::STATUS_ARCHIVED);
        } else {
            // Comma-separated, because a desk wanting "pending or rejected"
            // should not have to make two requests and join them itself.
            $wanted = array_values(array_intersect(
                array_filter(array_map('trim', explode(',', $status))),
                EmployeeDocument::STATUSES,
            ));

            $query->whereIn('employee_documents.status', $wanted ?: ['__none__']);
        }

        if ($this->truthy($request, 'expired')) {
            // Driven by the *date*, not by the stored `expired` status: a
            // document whose date passed an hour ago is expired whether or
            // not the scheduler has run, and a lagging cron must not make
            // this filter tell a lie.
            $query->whereNotNull('employee_documents.expiry_date')
                ->where('employee_documents.expiry_date', '<', now()->toDateString())
                ->where('employee_documents.status', '!=', EmployeeDocument::STATUS_ARCHIVED);
        }

        if ($this->truthy($request, 'expiring_soon')) {
            // Per-type window, computed in SQL from document_types rather
            // than from one global constant — an Emirates ID that lapses in
            // months and a passport that lapses in years are not the same
            // question, and one number could not answer both.
            $warning = max(0, (int) config('hrms.expiry.default_warning_days', 30));

            $query->whereNotNull('employee_documents.expiry_date')
                ->where('employee_documents.expiry_date', '>=', now()->toDateString())
                ->where('employee_documents.status', '!=', EmployeeDocument::STATUS_ARCHIVED)
                ->whereRaw(sprintf(
                    'employee_documents.expiry_date <= DATE_ADD(CURDATE(), INTERVAL COALESCE(NULLIF(document_types.expiry_warning_days, 0), %d) DAY)',
                    $warning,
                ));
        }

        if ($from = $this->param($request, 'expiry_from')) {
            $query->whereNotNull('employee_documents.expiry_date')
                ->where('employee_documents.expiry_date', '>=', $from);
        }

        if ($to = $this->param($request, 'expiry_to')) {
            $query->whereNotNull('employee_documents.expiry_date')
                ->where('employee_documents.expiry_date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->join('employees', 'employees.id', '=', 'employee_documents.employee_id')
                ->where(function (Builder $inner) use ($like) {
                    $inner->where('employee_documents.document_number', 'like', $like)
                        ->orWhere('employee_documents.original_name', 'like', $like)
                        ->orWhere('employees.first_name', 'like', $like)
                        ->orWhere('employees.last_name', 'like', $like);
                });
        }
    }

    private function truthy(Request $request, string $key): bool
    {
        $value = $this->param($request, $key);

        return $value !== null && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }
}
