<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollResource;
use App\Http\Responses\ApiResponse;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Notifications\NotificationMessage;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "May I have my payslip?" — asked by the employee, answered by HR.
 *
 *   POST /salary-slips/{payroll}/request   the employee asks (own slip only)
 *   POST /salary-slips/{payroll}/approve   HR releases it (salary_slips.manage)
 *   POST /salary-slips/{payroll}/reject    HR declines (salary_slips.manage)
 *
 * Asking notifies everyone who can release slips; the answer notifies the
 * employee. Once approved, PayrollPolicy::slip() lets the PDF through.
 */
class SalarySlipRequestController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function request(Request $request, Payroll $payroll): JsonResponse
    {
        $user = $request->user();

        if ($user->employee?->id !== $payroll->employee_id) {
            abort(403, 'You can only request your own payslip.');
        }

        if (! in_array($payroll->status, [Payroll::STATUS_PROCESSED, Payroll::STATUS_LOCKED], true)) {
            abort(409, 'This payslip is not ready yet. It can be requested once payroll is processed.');
        }

        if ($payroll->slip_request_status === Payroll::SLIP_APPROVED) {
            return ApiResponse::success('Already approved. You can download it.', new PayrollResource($payroll));
        }

        if ($payroll->slip_request_status === Payroll::SLIP_REQUESTED) {
            return ApiResponse::success('Already requested. HR has been notified.', new PayrollResource($payroll));
        }

        $payroll->slip_request_status = Payroll::SLIP_REQUESTED;
        $payroll->slip_requested_at = now();
        $payroll->slip_decided_at = null;
        $payroll->slip_decided_by = null;
        $payroll->save();

        $payroll->loadMissing('employee');
        $name = $payroll->employee?->full_name ?? 'An employee';

        $this->notifications->send(new NotificationMessage(
            type: 'payroll.slip_requested',
            title: 'Payslip download requested',
            body: sprintf('%s asked to download the %s payslip.', $name, $payroll->periodLabel()),
            recipients: User::permission('salary_slips.manage')->pluck('id')->all(),
            data: ['route' => '/salary-slips', 'payroll_id' => $payroll->id],
        ));

        return ApiResponse::success('Request sent. HR has been notified.', new PayrollResource($payroll));
    }

    public function approve(Request $request, Payroll $payroll): JsonResponse
    {
        return $this->decide($request, $payroll, Payroll::SLIP_APPROVED);
    }

    public function reject(Request $request, Payroll $payroll): JsonResponse
    {
        return $this->decide($request, $payroll, Payroll::SLIP_REJECTED);
    }

    private function decide(Request $request, Payroll $payroll, string $decision): JsonResponse
    {
        if ($payroll->slip_request_status !== Payroll::SLIP_REQUESTED) {
            abort(409, 'There is no pending request for this payslip.');
        }

        $payroll->slip_request_status = $decision;
        $payroll->slip_decided_at = now();
        $payroll->slip_decided_by = $request->user()->id;
        $payroll->save();

        $payroll->loadMissing('employee');
        $approved = $decision === Payroll::SLIP_APPROVED;

        $this->notifications->send(new NotificationMessage(
            type: 'payroll.slip_released',
            title: $approved ? 'Payslip ready to download' : 'Payslip request declined',
            body: $approved
                ? sprintf('Your %s payslip is ready. Open Salary slips to download it.', $payroll->periodLabel())
                : sprintf('Your request for the %s payslip was declined. Contact HR.', $payroll->periodLabel()),
            recipients: array_filter([$payroll->employee?->user_id]),
            data: ['route' => '/salary-slips', 'payroll_id' => $payroll->id],
        ));

        return ApiResponse::success(
            $approved ? 'Payslip released.' : 'Request declined.',
            new PayrollResource($payroll),
        );
    }
}
