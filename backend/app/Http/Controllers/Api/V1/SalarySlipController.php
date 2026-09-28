<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollResource;
use App\Http\Responses\PaginatedResponse;
use App\Models\Payroll;
use App\Services\Payroll\SalarySlipPdf;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/salary-slips
 *
 * The same rows as `/payroll`, behind a different grant - and that is the
 * whole reason for the pair rather than one endpoint with two filters.
 *
 * `salary_slips.view` is what an Employee holds to reach their own payslips;
 * `payroll.view` is what opens the payroll module. Splitting them means a
 * role can be given the documents without being given the ledger, and it
 * means `/payroll` can honestly 403 for an account that has never been near
 * a payroll screen while `/salary-slips` still works. The narrowing on the
 * list is {@see Visibility::salarySlipsFor()} - own rows unless
 * `salary_slips.manage` or `payroll.manage`.
 *
 * `pdf` is the Phase 7 contract exactly: rendered fresh, `no-store`, inline,
 * no path anywhere in the response, behind `salary_slips.view` *and*
 * PayrollPolicy::slip() so the permission only grants documents for
 * payrolls the same account could already read.
 */
class SalarySlipController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly SalarySlipPdf $pdf) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('slipIndex', Payroll::class);

        $query = Visibility::salarySlipsFor(
            Payroll::query()->with(['employee.department', 'employee.designation']),
            $request->user(),
        );

        if ($year = $this->param($request, 'year')) {
            $query->where('payroll_year', (int) $year);
        }

        if ($month = $this->param($request, 'month')) {
            $query->where('payroll_month', (int) $month);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($term = $this->searchTerm($request)) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->whereHas('employee', function ($q) use ($escaped) {
                $q->where(function ($inner) use ($escaped) {
                    $inner->where('first_name', 'like', $escaped)
                        ->orWhere('last_name', 'like', $escaped)
                        ->orWhere('employee_code', 'like', $escaped);
                });
            });
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['payroll_year', 'payroll_month', 'net_salary', 'created_at'], 'payroll_month'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Salary slips retrieved.',
            PayrollResource::collection($page->getCollection()),
            $page,
        );
    }

    public function pdf(Request $request, Payroll $payroll): Response
    {
        $this->authorize('slip', $payroll);

        // One query rather than one per relation: the document prints the
        // department, the designation and every line, and seven lazy
        // lookups on an endpoint already doing the most expensive thing the
        // API does is seven queries nobody budgeted for.
        $payroll->load([
            'employee.department',
            'employee.designation',
            'items' => fn ($query) => $query->orderBy('id'),
        ]);

        return $this->pdf->response($payroll);
    }
}
