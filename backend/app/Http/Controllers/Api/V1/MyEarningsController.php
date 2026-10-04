<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attendance;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/attendance/my-earnings?month=2026-10
 *
 * What each day you worked is worth, and the month so far — for the caller
 * only. A day's rate is the monthly salary divided by the same divisor
 * payroll prices a day with (`PayrollCalculationService::divisor()`), so the
 * figure here and the payslip's LOP arithmetic can never disagree.
 *
 * It is an estimate and says so: the payslip also carries allowances,
 * approved overtime, leave, LOP, loans and deductions, which belong to the
 * monthly run and not to a single day.
 */
class MyEarningsController extends Controller
{
    public function __construct(
        private readonly PayrollCalculationService $payroll,
        private readonly SettingsService $settings,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            abort(403, 'Only employees have earnings.');
        }

        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            abort(422, 'Month must look like 2026-10.');
        }

        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $currency = $this->settings->string('system.currency', '');

        if ($employee->salary === null || (float) $employee->salary <= 0) {
            return ApiResponse::success('No salary on file yet.', [
                'month' => $month,
                'available' => false,
                'currency' => $currency,
                'daily_rate' => null,
                'days' => [],
                'days_worked' => 0,
                'total' => null,
            ]);
        }

        $divisor = $this->payroll->divisor(
            $start->toDateString(),
            $end->toDateString(),
            $employee->primary_site_id,
        );
        $dailyRate = Money::round((float) $employee->salary / max($divisor, 1.0));

        $attendances = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('check_in_at')
            ->orderBy('attendance_date')
            ->get(['id', 'attendance_date', 'status', 'working_minutes']);

        $days = $attendances->map(fn (Attendance $attendance) => [
            'attendance_id' => $attendance->id,
            'date' => $attendance->attendance_date->toDateString(),
            'status' => $attendance->status,
            'pay' => $dailyRate,
        ])->values()->all();

        return ApiResponse::success('Earnings for '.$month.'.', [
            'month' => $month,
            'available' => true,
            'currency' => $currency,
            'daily_rate' => $dailyRate,
            'divisor' => $divisor,
            'days' => $days,
            'days_worked' => count($days),
            'total' => Money::round($dailyRate * count($days)),
            'note' => 'Estimate. Your payslip also includes allowances, approved overtime, leave and deductions.',
        ]);
    }
}
