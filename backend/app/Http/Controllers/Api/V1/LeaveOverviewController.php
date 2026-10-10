<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveBalanceService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/leave-overview?year=&search=
 *
 * HR's whole-company picture for a year: every active employee, how many
 * days of each leave type they have left, and how many requests are
 * pending, approved, rejected, cancelled or turned into loss of pay.
 */
class LeaveOverviewController extends Controller
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) ($request->query('year') ?: now()->year);

        $employees = Employee::query()
            ->where('employment_status', Employee::STATUS_ACTIVE)
            ->when(trim((string) $request->query('search', '')), function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like));
            });
        Visibility::employeesFor($employees, $user);
        $employees = $employees->orderBy('first_name')->limit(500)->get();

        // Make sure everyone has this year's pots before reading them.
        foreach ($employees as $employee) {
            $this->balances->forYear($employee, $year);
        }

        $ids = $employees->pluck('id');

        $balances = LeaveBalance::query()
            ->with('leaveType')
            ->whereIn('employee_id', $ids)
            ->where('year', $year)
            ->whereHas('leaveType')
            ->get()
            ->groupBy('employee_id');

        $counts = LeaveRequest::query()
            ->whereIn('employee_id', $ids)
            ->whereYear('start_date', $year)
            ->select('employee_id', 'status', DB::raw('count(*) as n'))
            ->groupBy('employee_id', 'status')
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(fn (Employee $e) => [
            'employee_id' => $e->id,
            'name' => $e->full_name,
            'employee_code' => $e->employee_code,
            'balances' => ($balances[$e->id] ?? collect())
                ->sortBy(fn (LeaveBalance $b) => $b->leaveType->name)
                ->values()
                ->map(fn (LeaveBalance $b) => [
                    'id' => $b->id,
                    'leave_type_id' => $b->leave_type_id,
                    'leave_type' => $b->leaveType->name,
                    'code' => $b->leaveType->code,
                    'entitlement' => (int) $b->entitlement,
                    'adjustment' => (int) $b->adjustment,
                    'carry_forward' => (int) $b->carry_forward,
                    'used' => (float) $b->used,
                    'pending' => (float) $b->pending,
                    'remaining' => $b->remaining(),
                    'unlimited' => (bool) $b->leaveType->is_unlimited,
                ]),
            'requests' => collect(LeaveRequest::STATUSES)
                ->reject(fn ($s) => $s === LeaveRequest::STATUS_DRAFT)
                ->mapWithKeys(fn ($s) => [$s => (int) (($counts[$e->id] ?? collect())->firstWhere('status', $s)?->n ?? 0)]),
        ])->values();

        return ApiResponse::success('Leave overview retrieved.', ['year' => $year, 'items' => $rows]);
    }
}
