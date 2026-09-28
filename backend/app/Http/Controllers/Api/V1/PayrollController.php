<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessPayrollRequest;
use App\Http\Resources\PayrollResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Payroll;
use App\Services\Payroll\PayrollService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/payroll
 *
 * The month in, the figures out - and four buttons that move a row along a
 * one-way street:
 *
 *   POST /payroll/process          run a month        (permission:payroll.process)
 *   POST /payroll/{payroll}/recalculate   re-run one  (permission:payroll.process)
 *   POST /payroll/{payroll}/review        reviewed    (permission:payroll.manage)
 *   POST /payroll/{payroll}/finalize      processed   (permission:payroll.manage)
 *   POST /payroll/{payroll}/lock          locked      (permission:payroll.lock)
 *
 * `index` and `show` carry no `permission:` middleware on `show` for the
 * same reason `employees/{id}` does not: the coarse gate is on the list, and
 * the *row* question - yours, or somebody you may read - is
 * PayrollPolicy's, asked where the model is already resolved. A middleware
 * on `show` would be a second, coarser answer to a question the policy
 * answers exactly.
 *
 * `summary` is registered before `{payroll}` and is guarded by
 * `payroll.summary.view` alone - it deliberately does not require
 * `payroll.view`, because "the company's payroll totals" and "may I see the
 * payroll list" are different grants and the whole point of a summary
 * permission is that a role can hold one without the other.
 */
class PayrollController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly PayrollService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payroll::class);

        $query = Visibility::payrollFor(
            Payroll::query()->with(['employee.department', 'employee.designation']),
            $request->user(),
        );

        if ($year = $this->param($request, 'year')) {
            $query->where('payroll_year', (int) $year);
        }

        if ($month = $this->param($request, 'month')) {
            $query->where('payroll_month', (int) $month);
        }

        if ($employeeId = $this->param($request, 'employee_id', 'employee')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($departmentId = $this->param($request, 'department_id', 'department')) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', (int) $departmentId));
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        // "Whose pay is this?" - a filter, not an override. The visibility
        // scope has already run, so asking for another employee's id narrows
        // to nothing rather than reaching through it.
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
            $this->sortColumn($request, ['payroll_year', 'payroll_month', 'net_salary', 'status', 'created_at'], 'payroll_month'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Payroll retrieved.',
            PayrollResource::collection($page->getCollection()),
            $page,
        );
    }

    /**
     * Company totals for a period, with no employee-level rows behind them.
     *
     * Answered by PayrollService::summary(), which aggregates and never
     * fetches - so a caller holding only this grant is not handed a list it
     * must then be trusted to filter.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('summary', Payroll::class);

        $year = (int) ($this->param($request, 'year') ?? now()->year);
        $month = $this->param($request, 'month');
        $departmentId = $this->param($request, 'department_id', 'department');

        return ApiResponse::success(
            'Payroll summary retrieved.',
            $this->service->summary(
                $year,
                $month === null ? null : (int) $month,
                $departmentId === null ? null : (int) $departmentId,
            ),
        );
    }

    public function show(Request $request, Payroll $payroll): JsonResponse
    {
        $this->authorize('view', $payroll);

        $payroll->load(['employee.department', 'employee.designation', 'items']);

        return ApiResponse::success('Payroll retrieved.', new PayrollResource($payroll));
    }

    /**
     * Run a whole month, or an explicit slice of it.
     *
     * The response is a *report* - counts and the period - and never the
     * figures themselves. Twenty thousand numbers in a response body would
     * be both unusable and an accidental bulk leak of every salary the
     * caller can reach; the rows are one `GET /payroll?year=&month=` away
     * and are narrowed by Visibility there.
     */
    public function process(ProcessPayrollRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $employeeIds = $validated['employee_ids'] ?? null;

        $report = $this->service->process(
            $request->user(),
            (int) $validated['year'],
            (int) $validated['month'],
            $employeeIds === null ? null : array_map('intval', $employeeIds),
        );

        return ApiResponse::success($this->reportMessage($report), $report);
    }

    public function recalculate(Request $request, Payroll $payroll): JsonResponse
    {
        $this->authorize('recalculate', $payroll);

        $payroll = $this->service->recalculate($payroll);

        return ApiResponse::success(
            'Payroll recalculated.',
            new PayrollResource($payroll->load('items')),
        );
    }

    public function review(Request $request, Payroll $payroll): JsonResponse
    {
        $this->authorize('review', $payroll);

        $payroll = $this->service->review($payroll, $request->user());

        return ApiResponse::success('Payroll marked as reviewed.', new PayrollResource($payroll));
    }

    public function finalize(Request $request, Payroll $payroll): JsonResponse
    {
        $this->authorize('finalize', $payroll);

        $payroll = $this->service->finalize($payroll, $request->user());

        return ApiResponse::success('Payroll processed.', new PayrollResource($payroll));
    }

    public function lock(Request $request, Payroll $payroll): JsonResponse
    {
        $this->authorize('lock', $payroll);

        $payroll = $this->service->lock($payroll, $request->user());

        return ApiResponse::success('Payroll locked.', new PayrollResource($payroll));
    }

    /**
     * A message that says what happened without saying what it cost.
     *
     * @param  array<string, int|string>  $report
     */
    private function reportMessage(array $report): string
    {
        $count = $report['created'] + $report['updated'];

        $message = sprintf('Payroll processed for %d/%d: %d row(s) calculated.', $report['month'], $report['year'], $report['calculated']);

        if ($report['draft'] > 0) {
            $message .= sprintf(' %d left in draft because no salary is on record.', $report['draft']);
        }

        if ($report['skipped'] > 0) {
            $message .= sprintf(' %d already reviewed, processed or locked and left untouched.', $report['skipped']);
        }

        if ($count === 0) {
            $message = 'No employees matched that period.';
        }

        return $message;
    }
}
