<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActOnPayrollAdjustment;
use App\Http\Requests\StorePayrollAdjustmentRequest;
use App\Http\Requests\UpdatePayrollAdjustmentRequest;
use App\Http\Resources\PayrollAdjustmentResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\PayrollAdjustment;
use App\Services\Payroll\PayrollAdjustmentService;
use App\Support\Money;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/payroll-adjustments
 *
 * Bonuses, other deductions and manual adjustments - the one-off inputs to
 * a single month's pay.
 *
 * The flow is deliberately two-step and there is no third:
 *
 *   pending -> approved   (and only `approved` ever reaches a pay run)
 *   pending -> rejected
 *
 * No `draft` state, because these rows are never in an unfinished form: an
 * adjustment is either recorded and awaiting a decision or it is not there.
 * No `generate`, no second approver - see SalaryCertificateService's note on
 * why the Phase 6 chain would add configuration without adding a decision
 * point, which applies here verbatim.
 *
 * Reads narrow to your own rows unless `payroll.manage`; every write needs
 * `payroll.manage`, including for the employee the adjustment is about (see
 * PayrollAdjustmentPolicy).
 */
class PayrollAdjustmentController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly PayrollAdjustmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollAdjustment::class);

        $query = Visibility::payrollAdjustmentsFor(
            PayrollAdjustment::query()->with(['employee.department', 'employee.designation']),
            $request->user(),
        );

        if ($employeeId = $this->param($request, 'employee_id', 'employee')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($year = $this->param($request, 'year')) {
            $query->where('payroll_year', (int) $year);
        }

        if ($month = $this->param($request, 'month')) {
            $query->where('payroll_month', (int) $month);
        }

        if ($type = $this->param($request, 'type')) {
            $query->where('type', $type);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($term = $this->searchTerm($request)) {
            $escaped = '%'.$this->escapeLike($term).'%';
            $query->where('description', 'like', $escaped);
        }

        $page = $query->orderBy('payroll_year', 'desc')
            ->orderBy('payroll_month', 'desc')
            ->orderBy(
                $this->sortColumn($request, ['type', 'amount', 'status', 'created_at'], 'created_at'),
                $this->sortDirection($request, 'desc'),
            )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Payroll adjustments retrieved.',
            PayrollAdjustmentResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, PayrollAdjustment $adjustment): JsonResponse
    {
        $this->authorize('view', $adjustment);

        $adjustment->load(['employee.department', 'employee.designation']);

        return ApiResponse::success('Payroll adjustment retrieved.', new PayrollAdjustmentResource($adjustment));
    }

    public function store(StorePayrollAdjustmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['amount'] = Money::decimal($validated['amount']);
        $validated['created_by'] = $request->user()->id;
        $validated['status'] = PayrollAdjustment::STATUS_PENDING;

        $adjustment = PayrollAdjustment::create($validated);

        return ApiResponse::created(
            'Payroll adjustment created.',
            new PayrollAdjustmentResource($adjustment->load(['employee.department', 'employee.designation'])),
        );
    }

    public function update(UpdatePayrollAdjustmentRequest $request, PayrollAdjustment $adjustment): JsonResponse
    {
        $this->authorize('update', $adjustment);

        $validated = $request->validated();

        if (array_key_exists('amount', $validated)) {
            $validated['amount'] = Money::decimal($validated['amount']);
        }

        $adjustment = $this->service->update($adjustment, $validated);

        return ApiResponse::success(
            'Payroll adjustment updated.',
            new PayrollAdjustmentResource($adjustment->load(['employee.department', 'employee.designation'])),
        );
    }

    public function approve(
        ActOnPayrollAdjustment $request,
        PayrollAdjustment $adjustment,
    ): JsonResponse {
        $this->authorize('approve', $adjustment);

        $adjustment = $this->service->approve(
            $adjustment,
            $request->user(),
            $request->validated('remarks'),
        );

        return ApiResponse::success(
            'Payroll adjustment approved.',
            new PayrollAdjustmentResource($adjustment->load(['employee.department', 'employee.designation'])),
        );
    }

    public function reject(
        ActOnPayrollAdjustment $request,
        PayrollAdjustment $adjustment,
    ): JsonResponse {
        $this->authorize('reject', $adjustment);

        $adjustment = $this->service->reject(
            $adjustment,
            $request->user(),
            $request->validated('remarks'),
        );

        return ApiResponse::success(
            'Payroll adjustment rejected.',
            new PayrollAdjustmentResource($adjustment->load(['employee.department', 'employee.designation'])),
        );
    }

    public function cancel(
        ActOnPayrollAdjustment $request,
        PayrollAdjustment $adjustment,
    ): JsonResponse {
        $this->authorize('cancel', $adjustment);

        $adjustment = $this->service->cancel(
            $adjustment,
            $request->validated('remarks'),
        );

        return ApiResponse::success(
            'Payroll adjustment cancelled.',
            new PayrollAdjustmentResource($adjustment->load(['employee.department', 'employee.designation'])),
        );
    }
}
