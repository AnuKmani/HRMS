<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActOnLeaveRequest;
use App\Http\Requests\StoreLeaveCertificateRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveRequestService;
use App\Services\Leave\SickCertificateStore;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/leave
 *
 * The only controller that writes a leave request, and it writes none of it.
 * Every transition goes through LeaveRequestService, so "which sequence can
 * turn a draft into an approval" has exactly one answer — this class decides
 * *who may ask*, and the service decides *whether the ask is legal*.
 *
 * Authorisation is split the same way as elsewhere:
 *
 *   - the coarse `permission:` middleware on each route is the door;
 *   - LeaveRequestPolicy is the row: is this request yours, are you the
 *     current approver, may you cancel it now;
 *   - the service is the state machine, and it answers 409 rather than 403
 *     when the row is simply not in a state where that transition exists.
 *
 * Nothing here reads `certificate_path` into a response either — see
 * LeaveRequestResource for why the path never leaves the server.
 */
class LeaveRequestController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly LeaveRequestService $service,
        private readonly SickCertificateStore $certificates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $query = Visibility::leaveFor(
            LeaveRequest::query()->with(['leaveType', 'employee', 'site']),
            $request->user(),
        );

        // One filter per business question, and each narrows rather than
        // replaces: an employee asking for "my pending leave in March" gets
        // the intersection without any of the three having to know about the
        // other two.
        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($typeId = $this->param($request, 'leave_type_id')) {
            $query->where('leave_type_id', (int) $typeId);
        }

        if ($employeeId = $this->param($request, 'employee_id')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($year = $this->param($request, 'year')) {
            $query->whereYear('start_date', (int) $year);
        }

        // Overlap, not containment: "show me leave in this range" means "any
        // request touching it", or a fortnight that started a week early
        // would silently vanish from the view it belongs in.
        if ($from = $this->param($request, 'from')) {
            $query->where('end_date', '>=', $from);
        }

        if ($to = $this->param($request, 'to')) {
            $query->where('start_date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where('reason', 'like', $like);
        }

        $page = $query->orderBy(
            $this->sortColumn(
                $request,
                ['start_date', 'end_date', 'status', 'requested_days', 'created_at'],
                'start_date',
            ),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Leave requests retrieved.',
            LeaveRequestResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        // The chain is loaded here and only here: a list of fifty rows each
        // pulling six approval records is the textbook N+1, and a list screen
        // has nowhere to put them anyway.
        $leaveRequest->load(['leaveType', 'employee', 'site', 'approvalRecords.actor']);

        return ApiResponse::success(
            'Leave request retrieved.',
            new LeaveRequestResource($leaveRequest),
        );
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $leave = $this->service->create($request->user(), $request->validated());
        $leave->load(['leaveType', 'employee', 'site']);

        return ApiResponse::created('Leave request created.', new LeaveRequestResource($leave));
    }

    public function update(UpdateLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $leave = $this->service->update(
            $request->user(),
            $leaveRequest,
            $request->validated(),
        );

        $leave->load(['leaveType', 'employee', 'site']);

        return ApiResponse::success('Leave request updated.', new LeaveRequestResource($leave));
    }

    public function submit(ActOnLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('submit', $leaveRequest);

        $leave = $this->service->submit($request->user(), $leaveRequest);

        return ApiResponse::success('Leave request submitted.', new LeaveRequestResource($leave));
    }

    public function approve(ActOnLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        // The policy asks ApprovalWorkflowService whether *this* user is the
        // approver of *this* request's *current* step. The service asks it
        // again under a row lock. Both, deliberately: one gives a clean 403
        // before any work happens, the other is what actually protects the
        // write against a second approval racing in.
        $this->authorize('approve', $leaveRequest);

        $leave = $this->service->approve(
            $request->user(),
            $leaveRequest,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success('Leave request approved.', new LeaveRequestResource($leave));
    }

    public function reject(ActOnLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('reject', $leaveRequest);

        $leave = $this->service->reject(
            $request->user(),
            $leaveRequest,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success('Leave request rejected.', new LeaveRequestResource($leave));
    }

    public function cancel(ActOnLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('cancel', $leaveRequest);

        $leave = $this->service->cancel(
            $request->user(),
            $leaveRequest,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success('Leave request cancelled.', new LeaveRequestResource($leave));
    }

    /* --------------------------------------------------------- certificate */

    /**
     * POST /api/v1/leave/{leaveRequest}/certificate
     *
     * The upload, and nothing about it is left to the client: the bytes are
     * validated, the name is generated by the server, the file lands on the
     * private `local` disk under a directory no route serves statically, and
     * the path is stored on the row rather than handed back.
     */
    public function storeCertificate(StoreLeaveCertificateRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $leave = $this->service->storeCertificate(
            $request->user(),
            $leaveRequest,
            $request->file('certificate'),
        );

        return ApiResponse::success(
            'Medical certificate filed.',
            new LeaveRequestResource($leave->load(['leaveType', 'employee', 'site'])),
        );
    }

    /**
     * GET /api/v1/leave/{leaveRequest}/certificate
     *
     * The only route to a stored medical document, and like the selfie it
     * makes you name the request first: you cannot list certificates, cannot
     * guess a path, and cannot fetch one for a request you could not already
     * read. `no-store` in SickCertificateStore so a shared device does not
     * keep a doctor's note in its browser cache.
     */
    public function certificate(Request $request, LeaveRequest $leaveRequest): StreamedResponse
    {
        $this->authorize('viewCertificate', $leaveRequest);

        $path = $leaveRequest->certificate_path;

        if ($path === null) {
            abort(404, 'There is no certificate attached to this request.');
        }

        $response = $this->certificates->response($path);

        if ($response === null) {
            abort(404, 'That certificate is no longer available.');
        }

        return $response;
    }
}
