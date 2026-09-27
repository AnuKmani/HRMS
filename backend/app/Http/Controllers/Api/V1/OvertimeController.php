<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActOnOvertimeRequest;
use App\Http\Requests\StoreOvertimeRequest;
use App\Http\Requests\UpdateOvertimeRequest;
use App\Http\Resources\OvertimeRequestResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\OvertimeRequest;
use App\Services\Overtime\OvertimeService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/overtime
 *
 * Structurally identical to the leave controller, deliberately: it is the
 * same eight verbs on the same engine, and the differences live in
 * OvertimeService (what completion means) rather than in the HTTP surface.
 *
 * `approved_minutes` rides on the approve action rather than on the resource
 * because it is not a property of the claim — it is what *this* approver is
 * granting at *this* step. A supervisor trimming 120 to 60 is a decision
 * made while signing, not an edit to what was asked for.
 */
class OvertimeController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly OvertimeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OvertimeRequest::class);

        $query = Visibility::overtimeFor(
            OvertimeRequest::query()->with(['employee', 'project', 'site']),
            $request->user(),
        );

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($employeeId = $this->param($request, 'employee_id')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($projectId = $this->param($request, 'project_id')) {
            $query->where('project_id', (int) $projectId);
        }

        if ($siteId = $this->param($request, 'site_id')) {
            $query->where('site_id', (int) $siteId);
        }

        if ($from = $this->param($request, 'from')) {
            $query->where('overtime_date', '>=', $from);
        }

        if ($to = $this->param($request, 'to')) {
            $query->where('overtime_date', '<=', $to);
        }

        if ($payroll = $this->param($request, 'payroll_eligible')) {
            $query->where('payroll_eligible', $payroll === 'true' || $payroll === '1');
        }

        $page = $query->orderBy(
            $this->sortColumn(
                $request,
                ['overtime_date', 'requested_minutes', 'approved_minutes', 'status', 'created_at'],
                'overtime_date',
            ),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Overtime requests retrieved.',
            OvertimeRequestResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('view', $overtimeRequest);

        $overtimeRequest->load(['employee', 'project', 'site', 'approvalRecords.actor']);

        return ApiResponse::success(
            'Overtime request retrieved.',
            new OvertimeRequestResource($overtimeRequest),
        );
    }

    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $overtime = $this->service->create($request->user(), $request->validated());

        return ApiResponse::created(
            'Overtime request created.',
            new OvertimeRequestResource($overtime->load(['employee', 'project', 'site'])),
        );
    }

    public function update(UpdateOvertimeRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $overtime = $this->service->update(
            $request->user(),
            $overtimeRequest,
            $request->validated(),
        );

        return ApiResponse::success(
            'Overtime request updated.',
            new OvertimeRequestResource($overtime->load(['employee', 'project', 'site'])),
        );
    }

    public function submit(ActOnOvertimeRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('submit', $overtimeRequest);

        $overtime = $this->service->submit($request->user(), $overtimeRequest);

        return ApiResponse::success(
            'Overtime request submitted.',
            new OvertimeRequestResource($overtime),
        );
    }

    public function approve(ActOnOvertimeRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        // Same two-gate arrangement as leave: the policy decides early and
        // cleanly whether this user is the current approver of this claim,
        // and OvertimeService asks again under a row lock before it writes.
        $this->authorize('approve', $overtimeRequest);

        $overtime = $this->service->approve(
            $request->user(),
            $overtimeRequest,
            $request->filled('approved_minutes') ? (int) $request->input('approved_minutes') : null,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success(
            'Overtime request approved.',
            new OvertimeRequestResource($overtime),
        );
    }

    public function reject(ActOnOvertimeRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('reject', $overtimeRequest);

        $overtime = $this->service->reject(
            $request->user(),
            $overtimeRequest,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success(
            'Overtime request rejected.',
            new OvertimeRequestResource($overtime),
        );
    }

    public function cancel(ActOnOvertimeRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('cancel', $overtimeRequest);

        $overtime = $this->service->cancel(
            $request->user(),
            $overtimeRequest,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success(
            'Overtime request cancelled.',
            new OvertimeRequestResource($overtime),
        );
    }
}
