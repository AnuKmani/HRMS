<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActOnLoan;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\UpdateLoanRequest;
use App\Http\Resources\LoanResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Loan;
use App\Services\Payroll\LoanService;
use App\Support\Money;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/loans
 *
 * Loans and salary advances - one endpoint pair, because they are one
 * object (see the loans migration for why).
 *
 * The flow, and nothing wider:
 *
 *   POST /loans                       draft     (loans.create, your own)
 *   PUT  /loans/{loan}                draft     (owner or loans.manage)
 *   POST /loans/{loan}/submit         pending   (owner or loans.manage)
 *   POST /loans/{loan}/approve        approved|active   (loans.approve)
 *   POST /loans/{loan}/reject         rejected  (loans.approve)
 *   POST /loans/{loan}/cancel         cancelled (owner while open, or manage)
 *
 * No `generate` endpoint and no `activate` endpoint: the schedule is minted
 * inside the approval and a loan opens for repayment at the first pay run
 * that could possibly repay it, both in LoanService. Exposing either would
 * be a second way to reach a state that already has exactly one path into
 * it, and the two would eventually disagree about whether installments
 * exist.
 *
 * Approving your own loan is refused twice - here by LoanPolicy::approve()
 * and again inside LoanService - because "nobody signs off on their own
 * debt" is too important to rest on a single gate.
 */
class LoanController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly LoanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Loan::class);

        $query = Visibility::loansFor(
            Loan::query()->with(['employee.department', 'employee.designation']),
            $request->user(),
        );

        if ($employeeId = $this->param($request, 'employee_id', 'employee')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($type = $this->param($request, 'loan_type', 'type')) {
            $query->where('loan_type', $type);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($term = $this->searchTerm($request)) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->where(function ($q) use ($escaped) {
                $q->where('reference', 'like', $escaped)
                    ->orWhere('remarks', 'like', $escaped)
                    ->orWhereHas('employee', function ($inner) use ($escaped) {
                        $inner->where('first_name', 'like', $escaped)
                            ->orWhere('last_name', 'like', $escaped)
                            ->orWhere('employee_code', 'like', $escaped);
                    });
            });
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['status', 'start_date', 'principal_amount', 'outstanding_balance', 'created_at'], 'created_at'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Loans retrieved.',
            LoanResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);

        $loan->load(['employee.department', 'employee.designation', 'installments']);

        return ApiResponse::success('Loan retrieved.', new LoanResource($loan));
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        $this->authorize('create', Loan::class);

        $validated = $request->validated();

        // `installment_amount` may be absent: the service divides the
        // principal evenly instead of trusting a figure nobody supplied, and
        // an even split always leaves a positive remainder for the last
        // payment. Both figures are normalised once, here, so nothing
        // downstream ever rounds them differently.
        foreach (['principal_amount', 'installment_amount'] as $money) {
            if (isset($validated[$money])) {
                $validated[$money] = Money::decimal($validated[$money]);
            }
        }

        $loan = $this->service->create($request->user(), $validated);

        return ApiResponse::created(
            'Loan created as a draft.',
            new LoanResource($loan->load('employee')),
        );
    }

    public function update(UpdateLoanRequest $request, Loan $loan): JsonResponse
    {
        // The route's coarse gate is `loans.view`, which every borrower
        // holds and which says nothing about *whose* loan this is. Without
        // the policy here, anybody who could read the list could rewrite
        // somebody else's principal - so the row question is asked, exactly
        // as it is on `show`.
        $this->authorize('update', $loan);

        $validated = $request->validated();
        unset($validated['employee_id']);

        foreach (['principal_amount', 'installment_amount'] as $money) {
            if (isset($validated[$money])) {
                $validated[$money] = Money::decimal($validated[$money]);
            }
        }

        $loan = $this->service->update($loan, $validated);

        return ApiResponse::success(
            'Loan updated.',
            new LoanResource($loan->load('employee')),
        );
    }

    public function submit(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('submit', $loan);

        $loan = $this->service->submit($loan);

        return ApiResponse::success('Loan submitted for approval.', new LoanResource($loan->load('employee')));
    }

    public function approve(ActOnLoan $request, Loan $loan): JsonResponse
    {
        $this->authorize('approve', $loan);

        $loan = $this->service->approve(
            $loan,
            $request->user(),
            $request->validated('remarks'),
        );

        return ApiResponse::success(
            'Loan approved.',
            new LoanResource($loan->load(['employee', 'installments'])),
        );
    }

    public function reject(ActOnLoan $request, Loan $loan): JsonResponse
    {
        $this->authorize('reject', $loan);

        $loan = $this->service->reject($loan, $request->user(), $request->validated('remarks'));

        return ApiResponse::success('Loan rejected.', new LoanResource($loan->load('employee')));
    }

    public function cancel(ActOnLoan $request, Loan $loan): JsonResponse
    {
        $this->authorize('cancel', $loan);

        $loan = $this->service->cancel($loan);

        return ApiResponse::success('Loan cancelled.', new LoanResource($loan->load('employee')));
    }
}
