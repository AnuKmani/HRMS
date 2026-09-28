<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAllowanceRequest;
use App\Http\Resources\AllowanceResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Allowance;
use App\Support\Money;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/allowances
 *
 * What an employee is paid on top of basic, per person.
 *
 * Reads narrow to your own rows unless `payroll.manage`; every write needs
 * `payroll.manage` - see AllowancePolicy for why the recipient may see their
 * own allowance but not restate it.
 *
 * There is no `destroy` here beyond the DELETE below doing a soft delete,
 * and no approval step: an allowance is a standing figure entered by
 * payroll, not a request anybody signs off. Bonuses are the thing that gets
 * approved, and they live on PayrollAdjustmentController.
 */
class AllowanceController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Allowance::class);

        $query = Visibility::allowancesFor(
            Allowance::query()->with(['employee.department', 'employee.designation']),
            $request->user(),
        );

        if ($employeeId = $this->param($request, 'employee_id', 'employee')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($code = $this->param($request, 'code')) {
            $query->where('code', $code);
        }

        if ($frequency = $this->param($request, 'frequency')) {
            $query->where('frequency', $frequency);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($term = $this->searchTerm($request)) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->where(function ($q) use ($escaped) {
                $q->where('label', 'like', $escaped)
                    ->orWhere('code', 'like', $escaped);
            });
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['code', 'label', 'amount', 'status', 'created_at'], 'created_at'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Allowances retrieved.',
            AllowanceResource::collection($page->getCollection()),
            $page,
        );
    }

    public function store(StoreAllowanceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // One place the figure is normalised, so a `1234.567` accepted by a
        // `numeric` rule never reaches a DECIMAL(12,2) column and is never
        // rounded by whatever wrote it last.
        $validated['amount'] = Money::decimal($validated['amount']);
        $validated['created_by'] = $request->user()->id;
        $validated['effective_from'] = $validated['effective_from'] ?? now()->toDateString();

        $allowance = Allowance::create($validated);

        return ApiResponse::created(
            'Allowance created.',
            new AllowanceResource($allowance->load(['employee.department', 'employee.designation'])),
        );
    }

    public function update(StoreAllowanceRequest $request, Allowance $allowance): JsonResponse
    {
        $this->authorize('update', $allowance);

        $validated = $request->validated();
        unset($validated['employee_id']);

        $validated['amount'] = Money::decimal($validated['amount']);

        $allowance->fill($validated);
        $allowance->save();

        return ApiResponse::success(
            'Allowance updated.',
            new AllowanceResource($allowance->load(['employee.department', 'employee.designation'])),
        );
    }

    /**
     * Soft delete. The row leaves the picker; payrolls already calculated
     * keep their own frozen line items, because those were computed from
     * something that existed at the time and rewriting them would be
     * changing history to match the present.
     */
    public function destroy(Allowance $allowance): JsonResponse
    {
        $this->authorize('delete', $allowance);

        $allowance->delete();

        return ApiResponse::success('Allowance deleted.');
    }
}
