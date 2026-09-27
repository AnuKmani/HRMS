<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLeaveBalanceRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Services\Leave\LeaveBalanceService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/leave-balances
 *
 * The read half of an employee's pot and the write half of an HR correction,
 * kept on one resource because they are one row.
 *
 * `GET` answers two different questions depending on what it is given:
 *
 *   ?employee_id=&year=   the *summary* screen for one person. This
 *                         materialises the year's pots first, which means it
 *                         creates rows — see LeaveBalanceService::forYear()
 *                         for why a balance that does not exist yet would
 *                         otherwise report "0 days" and quietly contradict
 *                         the leave type's own entitlement.
 *   anything else         the table as it stands, for an HR list. Existing
 *                         rows only; nobody's year is invented on their
 *                         behalf by a page they were not asking about.
 *
 * The materialisation is deliberately gated on *both* parameters rather than
 * either, and always runs inside the caller's visibility: it will not create
 * a pot for an employee this user is refused. That refusal is decided by
 * Visibility::mayViewOthersBalances() before anything else — see the comment
 * in index() for why asking employeeIsVisible() alone would be no check at
 * all for the ordinary Employee role.
 */
class LeaveBalanceController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveBalance::class);

        $user = $request->user();
        $employeeId = $this->param($request, 'employee_id');
        $year = (int) ($this->param($request, 'year') ?? now()->year);

        // The coarse question first, for exactly the reason LeaveBalancePolicy
        // asks it before employeeIsVisible(): an unlisted role such as
        // Employee is *not* narrowed by `employeesAreScopedFor()`, so
        // employeeIsVisible() answers "yes" to anybody. Asking it on its own
        // here would hand the ordinary employee a directory-wide list of
        // everybody's remaining leave.
        $maySeeOthers = Visibility::mayViewOthersBalances($user);

        if ($employeeId !== null) {
            $employee = Employee::query()->find((int) $employeeId);

            $visible = $employee !== null
                && ($user->employee?->id === $employee->id
                    || ($maySeeOthers && Visibility::employeeIsVisible($user, $employee)));

            if (! $visible) {
                abort(404, 'That employee is not in your scope.');
            }

            // Materialise first, read second. One extra query when both were
            // already present, and no lying "empty pot" the first time
            // somebody opens the screen. Never for a colleague the caller may
            // not read: a GET must not write a row they were refused.
            if ((int) $this->param($request, 'year') === $year) {
                $this->balances->forYear($employee, $year);
            }
        }

        $query = LeaveBalance::query()->with(['leaveType', 'employee']);

        if ($maySeeOthers) {
            $query->when(
                $employeeId !== null,
                fn ($q) => $q->where('employee_id', (int) $employeeId),
                // No employee named: restrict to the people this user may
                // read. Without this the endpoint is a directory-wide dump of
                // who has how much leave left, behind a coarse permission.
                fn ($q) => $q->whereHas('employee', fn ($inner) => Visibility::employeesFor($inner, $user)),
            );
        } else {
            // Fails closed, as attendance and leave do: their own rows and
            // nothing else, expressed as an id rather than a predicate that
            // matches nothing.
            $query->where('leave_balances.employee_id', $user->employee?->id ?? 0);
        }

        if ($typeId = $this->param($request, 'leave_type_id')) {
            $query->where('leave_type_id', (int) $typeId);
        }

        $query->where('year', $year);

        $page = $query->orderBy('year', 'desc')
            ->orderBy('leave_type_id')
            ->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Leave balances retrieved.',
            LeaveBalanceResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, LeaveBalance $leaveBalance): JsonResponse
    {
        $this->authorize('view', $leaveBalance);

        return ApiResponse::success(
            'Leave balance retrieved.',
            new LeaveBalanceResource($leaveBalance->load(['leaveType', 'employee'])),
        );
    }

    /**
     * PUT /api/v1/leave-balances/{leaveBalance}
     *
     * The one place an administrator changes a number by hand. It goes
     * through LeaveBalanceService::adjust() rather than `$balance->update()`
     * because that is the path which re-reads the row under `lockForUpdate()`
     * — an HR correction arriving at the same moment as an approval must not
     * land on a row that has just been written by somebody else.
     */
    public function update(UpdateLeaveBalanceRequest $request, LeaveBalance $leaveBalance): JsonResponse
    {
        $data = $request->validated();

        $balance = $this->balances->adjust(
            $leaveBalance,
            (int) ($data['entitlement'] ?? $leaveBalance->entitlement),
            (int) ($data['carry_forward'] ?? $leaveBalance->carry_forward),
            (int) ($data['adjustment'] ?? $leaveBalance->adjustment),
        );

        return ApiResponse::success(
            'Leave balance updated.',
            new LeaveBalanceResource($balance->load(['leaveType', 'employee'])),
        );
    }
}
