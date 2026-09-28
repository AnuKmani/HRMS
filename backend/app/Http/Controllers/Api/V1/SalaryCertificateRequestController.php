<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActOnSalaryCertificateRequest;
use App\Http\Requests\StoreSalaryCertificateRequest;
use App\Http\Resources\SalaryCertificateRequestResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\SalaryCertificateRequest;
use App\Services\Payroll\SalaryCertificatePdf;
use App\Services\Payroll\SalaryCertificateService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/salary-certificate-requests
 *
 * Ask, decide, issue - three verbs and no fourth.
 *
 *   POST   /{request}                      pending     (salary_certificates.view, own)
 *   POST   /{request}/approve              approved    (salary_certificates.manage)
 *   POST   /{request}/reject               rejected    (salary_certificates.manage)
 *   POST   /{request}/cancel               cancelled   (owner while pending, or manage)
 *   GET    /{request}/pdf                  generated   (row visibility)
 *
 * The PDF endpoint is where the one side effect on a read lives, and it is
 * fenced deliberately: `markGenerated()` moves `pending`-approved to
 * `generated` exactly once and then does nothing on every later render.
 * That is the shape Phase 6 uses for `certificate_checked_at`, and for the
 * same reason - a marker that moved on every read would answer "when did
 * somebody last look?" while pretending to answer "when was this issued?".
 *
 * Approval by the requester is refused twice, in the policy and again in
 * SalaryCertificateService, because a self-approved document stating your
 * own salary to a third party is exactly the sort of rule that should
 * survive a refactor.
 */
class SalaryCertificateRequestController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly SalaryCertificateService $service,
        private readonly SalaryCertificatePdf $pdf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalaryCertificateRequest::class);

        $query = Visibility::salaryCertificateRequestsFor(
            SalaryCertificateRequest::query()->with(['employee.department', 'employee.designation']),
            $request->user(),
        );

        if ($employeeId = $this->param($request, 'employee_id', 'employee')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($from = $this->param($request, 'from', 'date_from')) {
            $query->where('request_date', '>=', $from);
        }

        if ($to = $this->param($request, 'to', 'date_to')) {
            $query->where('request_date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->where(function ($q) use ($escaped) {
                $q->where('purpose', 'like', $escaped)
                    ->orWhereHas('employee', function ($inner) use ($escaped) {
                        $inner->where('first_name', 'like', $escaped)
                            ->orWhere('last_name', 'like', $escaped)
                            ->orWhere('employee_code', 'like', $escaped);
                    });
            });
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['request_date', 'status', 'created_at'], 'created_at'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Salary certificate requests retrieved.',
            SalaryCertificateRequestResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, SalaryCertificateRequest $salaryCertificateRequest): JsonResponse
    {
        $this->authorize('view', $salaryCertificateRequest);

        return ApiResponse::success(
            'Salary certificate request retrieved.',
            new SalaryCertificateRequestResource(
                $salaryCertificateRequest->load(['employee.department', 'employee.designation']),
            ),
        );
    }

    public function store(StoreSalaryCertificateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // "About me" is the default, so an employee never has to know their
        // own id - and a body naming somebody else is checked against the
        // caller's `salary_certificates.manage` rather than trusted.
        $validated['employee_id'] = (int) ($validated['employee_id'] ?? $request->user()->employee?->id);

        $certificate = $this->service->create($request->user(), $validated);

        return ApiResponse::created(
            'Salary certificate request submitted.',
            new SalaryCertificateRequestResource($certificate->load('employee')),
        );
    }

    public function approve(
        ActOnSalaryCertificateRequest $request,
        SalaryCertificateRequest $salaryCertificateRequest,
    ): JsonResponse {
        $this->authorize('approve', $salaryCertificateRequest);

        $certificate = $this->service->approve(
            $salaryCertificateRequest,
            $request->user(),
            $request->validated('remarks'),
        );

        return ApiResponse::success(
            'Salary certificate approved.',
            new SalaryCertificateRequestResource($certificate->load('employee')),
        );
    }

    public function reject(
        ActOnSalaryCertificateRequest $request,
        SalaryCertificateRequest $salaryCertificateRequest,
    ): JsonResponse {
        $this->authorize('reject', $salaryCertificateRequest);

        $certificate = $this->service->reject(
            $salaryCertificateRequest,
            $request->user(),
            $request->validated('remarks'),
        );

        return ApiResponse::success(
            'Salary certificate rejected.',
            new SalaryCertificateRequestResource($certificate->load('employee')),
        );
    }

    public function cancel(
        ActOnSalaryCertificateRequest $request,
        SalaryCertificateRequest $salaryCertificateRequest,
    ): JsonResponse {
        $this->authorize('cancel', $salaryCertificateRequest);

        $certificate = $this->service->cancel($salaryCertificateRequest);

        return ApiResponse::success(
            'Salary certificate request cancelled.',
            new SalaryCertificateRequestResource($certificate->load('employee')),
        );
    }

    /**
     * The document, rendered fresh and streamed inline. No stored file, no
     * public path, `no-store` - see SalaryCertificatePdf for why that is the
     * whole design rather than a detail of it.
     */
    public function pdf(
        Request $request,
        SalaryCertificateRequest $salaryCertificateRequest,
    ): Response {
        $this->authorize('pdf', $salaryCertificateRequest);

        $salaryCertificateRequest->load([
            'employee.department',
            'employee.designation',
        ]);

        // Render first, mark second. The marker is written only once a
        // document actually exists, so a failure inside dompdf leaves the
        // row `approved` and retryable rather than `generated` with nothing
        // to show for it - and the second and later renders find it already
        // marked and write nothing at all.
        $response = $this->pdf->response($salaryCertificateRequest);

        $this->service->markGenerated($salaryCertificateRequest);

        return $response;
    }
}
