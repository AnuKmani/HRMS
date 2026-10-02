<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attendance;
use App\Models\DailySiteReport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeTraining;
use App\Models\Expense;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Payroll\PayrollService;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The four role dashboards: `/dashboards/{employee,hr,project-manager,
 * management}`.
 *
 * **One coarse gate on every route (`dashboard.view`), then a per-block
 * permission check inside.** The middleware answers "may this account open
 * a dashboard at all?" — a single question with a single answer. What each
 * *block* may contain is decided here, one `can()` per block, and that is
 * the only place a multi-permission rule can be expressed: no combination
 * of route middleware can say "this endpoint, but only three of its five
 * cards". Two mechanisms, each doing the half it can do, with the finer
 * one on the inside where it can see what it is about to return.
 *
 * Every block announces itself in `available`, and only blocks that were
 * allowed *and* produced something appear in `blocks`. A client draws a
 * card from the switch rather than inferring permission from a missing
 * key — "you may not see this" and "there is nothing here yet" look
 * identical otherwise, and the honest answer to "where is my payslip
 * card?" is "your role cannot have one", not "the request came back
 * empty".
 *
 * **Every number is scoped with `Visibility`, not merely permission-
 * gated.** `attendance.view` lets an Employee ask; `Visibility::`
 * `attendanceFor()` is what makes the answer only their own rows. A count
 * is data about people — "present: 214" told to somebody who was never
 * allowed to see the other 213 is a leak in a smaller font.
 *
 * Nothing here computes a figure of its own that already exists
 * elsewhere: `PayrollService::summary()` for the payroll totals,
 * `Visibility` for every scoped query, `Money::round()` for every money
 * figure. A dashboard that re-derived a total would be a second source of
 * truth for a number the reports screen also shows, and the two would
 * drift the first time somebody changed one of them.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * GET /api/v1/dashboards/employee
     */
    public function employee(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $user->employee?->id;
        $available = [];
        $blocks = [];

        // --- Today -------------------------------------------------------
        $available['attendance'] = $user->can('attendance.view');

        if ($available['attendance']) {
            $record = Attendance::query()
                ->whereDate('attendance_date', today())
                ->where('employee_id', $employeeId ?? 0)
                ->first();

            $blocks['attendance'] = [
                'date' => today()->toDateString(),
                'checked_in_at' => $record?->check_in_at?->toIso8601String(),
                'checked_out_at' => $record?->check_out_at?->toIso8601String(),
                'status' => $record?->status,
                'working_minutes' => (int) ($record?->working_minutes ?? 0),
                'overtime_minutes' => (int) ($record?->overtime_minutes ?? 0),
                'present' => $record !== null,
            ];
        }

        // --- Leave -------------------------------------------------------
        $available['leave'] = $user->can('leave.view');

        if ($available['leave']) {
            $mine = LeaveRequest::query()->where('employee_id', $employeeId ?? 0);

            $blocks['leave'] = [
                'pending' => (clone $mine)->where('status', LeaveRequest::STATUS_PENDING)->count(),
                'approved_upcoming' => (clone $mine)
                    ->where('status', LeaveRequest::STATUS_APPROVED)
                    ->where('end_date', '>=', today())
                    ->count(),
            ];
        }

        $available['balances'] = $user->can('leave.balance.view');

        if ($available['balances']) {
            $balance = LeaveBalance::query()
                ->where('employee_id', $employeeId ?? 0)
                ->where('year', now()->year)
                ->selectRaw(
                    'COALESCE(SUM(entitlement), 0) + COALESCE(SUM(carry_forward), 0) + COALESCE(SUM(adjustment), 0) as total,'
                    .'COALESCE(SUM(used), 0) as used, COALESCE(SUM(pending), 0) as pending',
                )
                ->first();

            $total = (float) ($balance->total ?? 0);
            $used = (float) ($balance->used ?? 0);
            $pending = (float) ($balance->pending ?? 0);

            $blocks['balances'] = [
                'year' => now()->year,
                'entitled' => $total,
                'used' => $used,
                'pending' => $pending,
                'available' => round($total - $used - $pending, 2),
            ];
        }

        // --- Pay ---------------------------------------------------------
        // The one block that carries money. `salary_slips.view` is the
        // permission for *your own* slip — this role holds it — and
        // `Visibility::salarySlipsFor()` is what stops it reaching
        // anybody else's. Neither alone is enough, which is why both run.
        $available['payroll'] = $user->can('salary_slips.view');

        if ($available['payroll']) {
            $slip = Visibility::salarySlipsFor(Payroll::query(), $user)
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->first();

            $blocks['payroll'] = $slip === null ? [] : [
                'period' => $slip->periodLabel(),
                'net_salary' => Money::round($slip->net_salary),
                'currency' => $this->currency(),
                'status' => $slip->status,
                'has_slip' => true,
            ];
        }

        $available['notifications'] = true;
        $blocks['notifications'] = ['unread' => $this->notifications->unreadCount((int) $user->id)];

        return $this->respond('employee', $available, $blocks);
    }

    /**
     * GET /api/v1/dashboards/hr
     */
    public function hr(Request $request): JsonResponse
    {
        $user = $request->user();
        $available = [];
        $blocks = [];

        $available['workforce'] = $user->can('employees.view');

        if ($available['workforce']) {
            $byStatus = $this->headcount($user);
            $blocks['workforce'] = [
                'by_status' => $byStatus,
                'total' => array_sum($byStatus),
                'departments' => (int) Department::query()->count(),
            ];
        }

        $available['attendance'] = $user->can('attendance.view');

        if ($available['attendance']) {
            $blocks['attendance'] = [
                'date' => today()->toDateString(),
                'by_status' => $this->attendanceToday($user),
            ];
        }

        $available['approvals'] = $user->can('leave.view');

        if ($available['approvals']) {
            $blocks['approvals'] = [
                'leave_pending' => $this->count(
                    Visibility::leaveFor(LeaveRequest::query(), $user)->where('status', LeaveRequest::STATUS_PENDING),
                ),
                'overtime_pending' => $user->can('overtime.view')
                    ? $this->count(
                        Visibility::overtimeFor(OvertimeRequest::query(), $user)
                            ->where('status', OvertimeRequest::STATUS_PENDING),
                    )
                    : null,
                'expenses_pending' => $user->can('expenses.view')
                    ? $this->count(
                        Visibility::expensesFor(Expense::query(), $user)->where('status', Expense::STATUS_PENDING),
                    )
                    : null,
            ];
        }

        $available['compliance'] = $user->can('documents.expiry.view') || $user->can('training.expiry.view');

        if ($available['compliance']) {
            $window = [today()->toDateString(), today()->addDays(30)->toDateString()];

            $blocks['compliance'] = [
                'documents_expiring' => $user->can('documents.expiry.view')
                    ? EmployeeDocument::query()
                        ->whereIn('status', ['pending', 'valid'])
                        ->whereNotNull('expiry_date')
                        ->whereBetween('expiry_date', $window)
                        ->count()
                    : null,
                'certificates_expiring' => $user->can('training.expiry.view')
                    ? EmployeeTraining::query()
                        ->whereNotNull('certificate_expiry_date')
                        ->whereBetween('certificate_expiry_date', $window)
                        ->count()
                    : null,
                'within_days' => 30,
            ];
        }

        $available['payroll'] = $user->can('payroll.summary.view');

        if ($available['payroll']) {
            // Aggregates only, by construction: `PayrollService::summary()`
            // never does a `->get()` of rows, so this block cannot leak an
            // individual salary even if the gate above were wrong.
            $blocks['payroll'] = $this->payroll->summary((int) now()->year);
        }

        $available['notifications'] = true;
        $blocks['notifications'] = ['unread' => $this->notifications->unreadCount((int) $user->id)];

        return $this->respond('hr', $available, $blocks);
    }

    /**
     * GET /api/v1/dashboards/project-manager
     */
    public function projectManager(Request $request): JsonResponse
    {
        $user = $request->user();
        $available = [];
        $blocks = [];

        $available['sites'] = $user->can('sites.view');

        if ($available['sites']) {
            $scoped = Visibility::projectsAndSitesFor(Site::query(), $user);

            $blocks['sites'] = [
                'active' => $this->count((clone $scoped)->where('status', Site::STATUS_ACTIVE)),
                'total' => $this->count($scoped),
            ];
        }

        $available['site_reports'] = $user->can('daily_site_reports.view');

        if ($available['site_reports']) {
            $today = Visibility::dailySiteReportsFor(DailySiteReport::query(), $user)
                ->whereDate('report_date', today());

            $blocks['site_reports'] = [
                'date' => today()->toDateString(),
                'filed_today' => $this->count(clone $today),
                'awaiting_review' => $this->count(
                    (clone $today)->where('status', DailySiteReport::STATUS_SUBMITTED),
                ),
            ];
        }

        $available['attendance'] = $user->can('attendance.view');

        if ($available['attendance']) {
            $blocks['attendance'] = [
                'date' => today()->toDateString(),
                'by_status' => $this->attendanceToday($user),
            ];
        }

        $available['approvals'] = $user->can('leave.view');

        if ($available['approvals']) {
            $blocks['approvals'] = [
                'leave_pending' => $this->count(
                    Visibility::leaveFor(LeaveRequest::query(), $user)->where('status', LeaveRequest::STATUS_PENDING),
                ),
                'overtime_pending' => $user->can('overtime.view')
                    ? $this->count(
                        Visibility::overtimeFor(OvertimeRequest::query(), $user)
                            ->where('status', OvertimeRequest::STATUS_PENDING),
                    )
                    : null,
            ];
        }

        $available['team'] = $user->can('employees.view');

        if ($available['team']) {
            $byStatus = $this->headcount($user);
            $blocks['team'] = [
                'by_status' => $byStatus,
                'total' => array_sum($byStatus),
            ];
        }

        $available['notifications'] = true;
        $blocks['notifications'] = ['unread' => $this->notifications->unreadCount((int) $user->id)];

        return $this->respond('project-manager', $available, $blocks);
    }

    /**
     * GET /api/v1/dashboards/management
     */
    public function management(Request $request): JsonResponse
    {
        $user = $request->user();
        $available = [];
        $blocks = [];

        $available['workforce'] = $user->can('employees.view');

        if ($available['workforce']) {
            $byStatus = $this->headcount($user);
            $blocks['workforce'] = [
                'by_status' => $byStatus,
                'total' => array_sum($byStatus),
                'departments' => (int) Department::query()->count(),
            ];
        }

        $available['attendance'] = $user->can('attendance.view');

        if ($available['attendance']) {
            $blocks['attendance'] = [
                'date' => today()->toDateString(),
                'by_status' => $this->attendanceToday($user),
            ];
        }

        $available['payroll'] = $user->can('payroll.summary.view');

        if ($available['payroll']) {
            $blocks['payroll'] = $this->payroll->summary((int) now()->year);
        }

        $available['projects'] = $user->can('projects.view');

        if ($available['projects']) {
            $scoped = Visibility::projectsAndSitesFor(Project::query(), $user);

            $blocks['projects'] = [
                'active' => $this->count((clone $scoped)->where('status', Project::STATUS_ACTIVE)),
                'total' => $this->count($scoped),
            ];
        }

        $available['expenses'] = $user->can('expenses.view');

        if ($available['expenses']) {
            $visible = Visibility::expensesFor(Expense::query(), $user);

            $approved = (clone $visible)
                ->where('status', Expense::STATUS_APPROVED)
                ->whereMonth('expense_date', now()->month)
                ->whereYear('expense_date', now()->year);

            $blocks['expenses'] = [
                'pending' => $this->count((clone $visible)->where('status', Expense::STATUS_PENDING)),
                'approved_this_month' => Money::round((clone $approved)->sum('amount')),
                'currency' => $this->currency(),
            ];
        }

        $available['leave'] = $user->can('leave.view');

        if ($available['leave']) {
            $visible = Visibility::leaveFor(LeaveRequest::query(), $user);

            $blocks['leave'] = [
                'pending' => $this->count((clone $visible)->where('status', LeaveRequest::STATUS_PENDING)),
                'lop_days_this_month' => round(
                    (clone $visible)
                        ->whereMonth('start_date', now()->month)
                        ->whereYear('start_date', now()->year)
                        ->sum('lop_days'),
                    2,
                ),
            ];
        }

        $available['notifications'] = true;
        $blocks['notifications'] = ['unread' => $this->notifications->unreadCount((int) $user->id)];

        return $this->respond('management', $available, $blocks);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The shared envelope. `available` carries every block name the
     * endpoint *could* draw — an off switch is a value, not an absent key
     * — and `blocks` carries only what was allowed and produced something,
     * so a renderer never has to reconcile the two lists.
     *
     * @param  array<string, bool>  $available
     * @param  array<string, mixed>  $blocks
     */
    private function respond(string $role, array $available, array $blocks): JsonResponse
    {
        return ApiResponse::success('Dashboard.', (object) [
            'role' => $role,
            'available' => (object) $available,
            'blocks' => (object) array_filter(
                $blocks,
                fn ($value): bool => $value !== null && $value !== [],
            ),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Headcount by employment status, scoped — an Employee asking sees
     * themselves, a Project Manager their own team, HR the company.
     *
     * @return array<string, int>
     */
    private function headcount(User $user): array
    {
        return $this->breakdown(
            Visibility::employeesFor(Employee::query(), $user),
            'employment_status',
        );
    }

    /**
     * @return array<string, int>
     */
    private function attendanceToday(User $user): array
    {
        return $this->breakdown(
            Visibility::attendanceFor(Attendance::query(), $user)
                ->whereDate('attendance_date', today()),
            'status',
        );
    }

    /**
     * `{value => rows}` for a column. Grouping *here* rather than in the
     * caller, because `count()` over a grouped select answers a different
     * question (how many rows in the first group) and a dashboard that
     * reported "3 employees" for a fourteen-person company would be a
     * dashboard nobody could trust.
     *
     * @return array<string, int>
     */
    private function breakdown(Builder $query, string $column): array
    {
        $breakdown = [];

        $rows = $query
            ->selectRaw($column.', COUNT(*) as total')
            ->groupBy($column)
            ->get();

        foreach ($rows as $row) {
            $breakdown[(string) $row->getAttribute($column)] = (int) $row->total;
        }

        return $breakdown;
    }

    private function count(Builder $query): int
    {
        return (int) $query->count();
    }

    private function currency(): string
    {
        return $this->settings->string('system.currency', 'AED');
    }
}
