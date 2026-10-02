<?php

namespace App\Providers;

use App\Events\AssetHandover;
use App\Events\AttendanceReminder;
use App\Events\EmployeeDocumentExpired;
use App\Events\EmployeeDocumentExpiring;
use App\Events\EmployeeTrainingExpired;
use App\Events\EmployeeTrainingExpiring;
use App\Events\ExpenseDecided;
use App\Events\LeaveConvertedToLop;
use App\Events\LeaveDecided;
use App\Events\LeaveSubmitted;
use App\Events\OvertimeDecided;
use App\Events\SalaryCertificateDecided;
use App\Events\SalarySlipPublished;
use App\Events\SickCertificateReminder;
use App\Events\SiteAssigned;
use App\Models\Employee;
use App\Services\Notifications\ApprovalAudience;
use App\Services\Notifications\Fcm\DisabledFcmGateway;
use App\Services\Notifications\Fcm\FcmGateway;
use App\Services\Notifications\Fcm\FirebaseFcmGateway;
use App\Services\Notifications\NotificationMessage;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The whole notification catalogue, in one file.
 *
 * Every listener here does the same three things and nothing else: work out
 * **who**, pick a **type** from `NotificationCategory::TYPES`, and hand a
 * `NotificationMessage` to `NotificationService`. No listener opens a
 * database transaction, writes an inbox row, touches a device token or has
 * ever heard of FCM — that is one service's job, and keeping it there is
 * what stops "why did the leave message differ from the overtime message?"
 * from becoming a question anybody has to answer.
 *
 * Listing them all in one place is deliberate. This file *is* the answer to
 * "what does this system tell people, and when?", and an answer spread
 * across fourteen classes spread across fourteen directories is not an
 * answer.
 *
 * **Registered explicitly rather than auto-discovered.** Laravel will find
 * listeners in `app/Listeners` on its own, but a registration you cannot
 * see is a registration you have to go looking for when one of them stops
 * firing, and the ordering of these closures is part of how the module
 * reads.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One gateway, chosen per resolve rather than cached for the life
        // of the process: a long-running queue worker that started before
        // credentials were added must not stay deaf until the next deploy.
        $this->app->bind(FcmGateway::class, function () {
            $firebase = new FirebaseFcmGateway;

            return $firebase->available() ? $firebase : new DisabledFcmGateway;
        });

        $this->app->singleton(ApprovalAudience::class, fn () => new ApprovalAudience);
    }

    public function boot(): void
    {
        $this->registerAttendanceListeners();
        $this->registerExpiryListeners();
        $this->registerLeaveListeners();
        $this->registerDecisionListeners();
        $this->registerPayrollListeners();
        $this->registerAssignmentListeners();
    }

    /* --------------------------------------------------------- attendance */

    /**
     * The only notification the system initiates about a person's own day.
     *
     * Everything else in this file answers something that *happened*; this
     * one answers something that has not — and so, unlike the rest, it
     * carries a dedupe key. The reminder job runs every fifteen minutes
     * across a window that can be an hour wide, and without the key a
     * person would be told four times before their shift started.
     */
    private function registerAttendanceListeners(): void
    {
        Event::listen(AttendanceReminder::class, function (AttendanceReminder $event) {
            $employee = $event->employee;

            $this->notify(
                type: 'attendance.reminder',
                title: 'Time to check in',
                body: 'Your shift is starting and you have not checked in yet.',
                recipients: [$this->userFor($employee)],
                data: ['route' => '/attendance'],
                dedupeKey: 'attendance:'.today()->toDateString(),
            );
        });
    }

    /* ------------------------------------------------------------- expiry */

    /**
     * Document and training certificates running out — the two scans in
     * `routes/console.php` already raise these; here is where they turn
     * into words.
     *
     * The audience is **the person whose certificate it is**, and only
     * them. HR's half of the same fact is the `/documents/expiring` and
     * `/training/expiring` screens plus the dashboard blocks, both of which
     * are queries over the live rows; an inbox copy would go stale the
     * moment the document was renewed and would be one more thing to
     * retract.
     */
    private function registerExpiryListeners(): void
    {
        Event::listen(EmployeeDocumentExpiring::class, function (EmployeeDocumentExpiring $event) {
            $document = $event->document;

            $this->notify(
                type: 'document.expiring',
                title: 'Document expiring soon',
                body: sprintf(
                    '%s expires on %s.',
                    $document->documentType?->name ?? 'A document',
                    $event->expiresOn,
                ),
                recipients: [$this->userFor($document->employee)],
                data: [
                    'route' => '/documents/'.$document->id,
                    'employee_document_id' => $document->id,
                ],
            );
        });

        Event::listen(EmployeeDocumentExpired::class, function (EmployeeDocumentExpired $event) {
            $document = $event->document;

            $this->notify(
                type: 'document.expired',
                title: 'Document has expired',
                body: sprintf(
                    '%s has expired. Renew it to keep your records current.',
                    $document->documentType?->name ?? 'A document',
                ),
                recipients: [$this->userFor($document->employee)],
                data: [
                    'route' => '/documents/'.$document->id,
                    'employee_document_id' => $document->id,
                ],
            );
        });

        Event::listen(EmployeeTrainingExpiring::class, function (EmployeeTrainingExpiring $event) {
            $training = $event->training;

            $this->notify(
                type: 'training.expiring',
                title: 'Training certificate expiring soon',
                body: sprintf(
                    '%s expires on %s.',
                    $training->trainingProgram?->name ?? 'A training certificate',
                    $event->expiresOn,
                ),
                recipients: [$this->userFor($training->employee)],
                data: [
                    'route' => '/training/'.$training->id,
                    'employee_training_id' => $training->id,
                ],
            );
        });

        Event::listen(EmployeeTrainingExpired::class, function (EmployeeTrainingExpired $event) {
            $training = $event->training;

            $this->notify(
                type: 'training.expired',
                title: 'Training certificate has expired',
                body: sprintf(
                    '%s has expired. Book a refresher to regain compliance.',
                    $training->trainingProgram?->name ?? 'A training certificate',
                ),
                recipients: [$this->userFor($training->employee)],
                data: [
                    'route' => '/training/'.$training->id,
                    'employee_training_id' => $training->id,
                ],
            );
        });
    }

    /* -------------------------------------------------------------- leave */

    /**
     * Submission goes **to the people holding the chain**; the answers go
     * **to the person who asked**. Two directions, because those are the
     * only two people a leave request is about — notifying everybody with
     * `leaves.approve` would tell a hundred people about one holiday.
     */
    private function registerLeaveListeners(): void
    {
        Event::listen(LeaveSubmitted::class, function (LeaveSubmitted $event) {
            $leave = $event->leaveRequest;
            $leave->loadMissing(['employee', 'leaveType']);

            $this->notify(
                type: 'leave.submitted',
                title: 'Leave request waiting for you',
                body: sprintf('%s requested %s.', $leave->employee?->full_name ?? 'An employee', $leave->summary()),
                recipients: $this->app->make(ApprovalAudience::class)
                    ->openApprovers('leave_request', (int) $leave->id),
                data: [
                    'route' => '/leave/'.$leave->id,
                    'leave_request_id' => $leave->id,
                ],
            );
        });

        Event::listen(LeaveDecided::class, function (LeaveDecided $event) {
            $leave = $event->leaveRequest;
            $leave->loadMissing(['employee', 'leaveType']);

            $approved = $event->decision === LeaveDecided::APPROVED;

            $this->notify(
                type: $approved ? 'leave.approved' : 'leave.rejected',
                title: $approved ? 'Leave approved' : 'Leave rejected',
                body: sprintf(
                    'Your leave from %s to %s was %s.',
                    $leave->start_date?->format('j M Y') ?? '',
                    $leave->end_date?->format('j M Y') ?? '',
                    $event->decision,
                ),
                recipients: [$this->userFor($leave->employee)],
                data: [
                    'route' => '/leave/'.$leave->id,
                    'leave_request_id' => $leave->id,
                ],
            );
        });

        Event::listen(LeaveConvertedToLop::class, function (LeaveConvertedToLop $event) {
            $leave = $event->leaveRequest;
            $leave->loadMissing(['employee', 'leaveType']);

            $this->notify(
                type: 'leave.lop_converted',
                title: 'Leave converted to Loss of Pay',
                body: sprintf(
                    'Your leave from %s to %s became Loss of Pay because the medical certificate did not arrive.',
                    $leave->start_date?->format('j M Y') ?? '',
                    $leave->end_date?->format('j M Y') ?? '',
                ),
                recipients: [$this->userFor($leave->employee)],
                data: [
                    'route' => '/leave/'.$leave->id,
                    'leave_request_id' => $leave->id,
                ],
            );
        });

        Event::listen(SickCertificateReminder::class, function (SickCertificateReminder $event) {
            $leave = $event->leaveRequest;
            $leave->loadMissing(['employee', 'leaveType']);

            $this->notify(
                type: 'leave.sick_certificate_reminder',
                title: 'Medical certificate due',
                body: sprintf(
                    'A medical certificate is due for your leave starting %s. Without it the leave becomes Loss of Pay.',
                    $leave->start_date?->format('j M Y') ?? '',
                ),
                recipients: [$this->userFor($leave->employee)],
                data: [
                    'route' => '/leave/'.$leave->id,
                    'leave_request_id' => $leave->id,
                ],
                // Mandatory. Being told that pay is about to be cut is not
                // a preference — see NotificationCategory::MANDATORY.
                mandatory: true,
                // The hourly deadline job will raise this again every hour
                // until the certificate lands or the request converts. The
                // key is what stops that being four messages a day.
                dedupeKey: 'sick_cert:'.$leave->id,
            );
        });
    }

    /* ---------------------------------------------------------- decisions */

    /**
     * Overtime and expense, both answered the same way: the decision to
     * the person it happened to, carrying who made it, with a pointer back
     * to the record rather than a copy of its numbers.
     */
    private function registerDecisionListeners(): void
    {
        Event::listen(OvertimeDecided::class, function (OvertimeDecided $event) {
            $overtime = $event->overtimeRequest;
            $overtime->loadMissing('employee');

            $approved = $event->decision === OvertimeDecided::APPROVED;

            $this->notify(
                type: $approved ? 'overtime.approved' : 'overtime.rejected',
                title: $approved ? 'Overtime approved' : 'Overtime rejected',
                body: sprintf(
                    'Your overtime on %s was %s.',
                    $overtime->overtime_date?->format('j M Y') ?? '',
                    $event->decision,
                ),
                recipients: [$this->userFor($overtime->employee)],
                data: [
                    'route' => '/overtime/'.$overtime->id,
                    'overtime_request_id' => $overtime->id,
                ],
            );
        });

        Event::listen(ExpenseDecided::class, function (ExpenseDecided $event) {
            $expense = $event->expense;
            $expense->loadMissing('employee');

            $approved = $event->decision === ExpenseDecided::APPROVED;

            $this->notify(
                type: $approved ? 'expense.approved' : 'expense.rejected',
                title: $approved ? 'Expense approved' : 'Expense rejected',
                body: sprintf('Your expense claim was %s.', $event->decision),
                recipients: [$this->userFor($expense->employee)],
                data: [
                    'route' => '/expenses/'.$expense->id,
                    'expense_id' => $expense->id,
                ],
            );
        });
    }

    /* ------------------------------------------------------------ payroll */

    /**
     * Two payroll messages, and neither of them contains a number.
     *
     * "Your payslip is ready" and "your certificate was approved" are
     * pointers. The figure lives behind `payroll.view`, and an inbox row
     * that repeated it would be a second copy of the salary that any
     * `notifications` reader could see — which is precisely what the
     * migration's rule forbids.
     */
    private function registerPayrollListeners(): void
    {
        Event::listen(SalarySlipPublished::class, function (SalarySlipPublished $event) {
            $payroll = $event->payroll;
            $payroll->loadMissing('employee');

            $period = $payroll->period_start?->format('F Y') ?? '';

            $this->notify(
                type: 'payroll.slip_available',
                title: 'Salary slip available',
                body: $period === ''
                    ? 'Your salary slip is ready to view.'
                    : "Your salary slip for {$period} is ready to view.",
                recipients: [$this->userFor($payroll->employee)],
                data: [
                    'route' => '/salary-slips',
                    'payroll_id' => $payroll->id,
                ],
            );
        });

        Event::listen(SalaryCertificateDecided::class, function (SalaryCertificateDecided $event) {
            $request = $event->request;
            $request->loadMissing('employee');

            $approved = $event->decision === SalaryCertificateDecided::APPROVED;

            $this->notify(
                type: 'payroll.certificate_status',
                title: $approved ? 'Salary certificate approved' : 'Salary certificate rejected',
                body: $approved
                    ? 'Your salary certificate was approved and is ready to download.'
                    : 'Your salary certificate request was rejected.',
                recipients: [$this->userFor($request->employee)],
                data: [
                    'route' => '/salary-certificates/'.$request->id,
                    'salary_certificate_request_id' => $request->id,
                ],
            );
        });
    }

    /* --------------------------------------------------------- assignment */

    private function registerAssignmentListeners(): void
    {
        Event::listen(SiteAssigned::class, function (SiteAssigned $event) {
            $assignment = $event->assignment;
            $assignment->loadMissing(['employee', 'site']);

            $this->notify(
                type: 'site.assigned',
                title: 'Site assignment',
                body: sprintf(
                    'You have been assigned to %s from %s.',
                    $assignment->site?->name ?? 'a site',
                    $assignment->start_date?->format('j M Y') ?? '',
                ),
                recipients: [$this->userFor($assignment->employee)],
                data: [
                    // No `/sites/:id` deep link: a site page is not where
                    // an employee looks for their own roster, and the home
                    // screen's "today" block already answers that.
                    'route' => '/home',
                    'employee_site_assignment_id' => $assignment->id,
                    'site_id' => $assignment->site_id,
                ],
            );
        });

        Event::listen(AssetHandover::class, function (AssetHandover $event) {
            $assignment = $event->assignment;
            $assignment->loadMissing(['employee', 'asset']);

            $assigned = $event->kind === AssetHandover::ASSIGNED;

            $this->notify(
                type: $assigned ? 'asset.assigned' : 'asset.returned',
                title: $assigned ? 'Asset assigned to you' : 'Asset return recorded',
                body: $assigned
                    ? sprintf(
                        '%s was handed to you on %s.',
                        $assignment->asset?->name ?? 'An asset',
                        $assignment->assigned_date?->format('j M Y') ?? '',
                    )
                    : sprintf('%s has been recorded as returned.', $assignment->asset?->name ?? 'An asset'),
                recipients: [$this->userFor($assignment->employee)],
                data: [
                    'route' => '/assets/'.$assignment->asset_id,
                    'asset_id' => $assignment->asset_id,
                    'asset_assignment_id' => $assignment->id,
                ],
            );
        });
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The one line every listener ends on.
     *
     * @param  array<int, int|null>  $recipients
     * @param  array<string, scalar|null>  $data
     */
    private function notify(
        string $type,
        string $title,
        string $body,
        array $recipients,
        array $data = [],
        bool $mandatory = false,
        ?string $dedupeKey = null,
    ): void {
        $this->app->make(NotificationService::class)->send(
            new NotificationMessage($type, $title, $body, $recipients, $data, $mandatory, $dedupeKey),
        );
    }

    /**
     * The user behind an employee, or null for an employee with no login.
     *
     * Null is filtered out downstream rather than here: a message to
     * "nobody" is a no-op the service already knows how to have.
     */
    private function userFor(?Employee $employee): ?int
    {
        return $employee?->user_id === null ? null : (int) $employee->user_id;
    }
}
