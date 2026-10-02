<?php

use App\Http\Controllers\Api\V1\AllowanceController;
use App\Http\Controllers\Api\V1\ApprovalWorkflowController;
use App\Http\Controllers\Api\V1\AssetAssignmentController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AssetTypeController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientSettingsController;
use App\Http\Controllers\Api\V1\DailySiteReportController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DesignationController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\EmployeeBankAccountController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeSiteAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeTrainingController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\MovementController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OvertimeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PayrollAdjustmentController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportExportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SalaryCertificateRequestController;
use App\Http\Controllers\Api\V1\SalarySlipController;
use App\Http\Controllers\Api\V1\SiteActivityReportController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\SiteVisitController;
use App\Http\Controllers\Api\V1\TimesheetController;
use App\Http\Controllers\Api\V1\TrainingProgramController;
use App\Http\Controllers\Api\V1\TrainingTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Laravel's api routing group already prefixes this file with /api, and the
| group below adds /v1 — so every endpoint here lives at /api/v1/...
|
| Versioning the path (rather than a header) means an older Flutter build in
| the wild keeps hitting routes that still exist when v2 ships.
|
| Two rules hold for everything in this file:
|
|   1. Success and failure both use the ApiResponse envelope, so the client
|      never has to guess at a response shape.
|   2. Authorization happens here, on the server. Flutter hiding a button is
|      a convenience; `permission:` on the route is the actual boundary.
|
*/

Route::prefix('v1')->group(function () {

    /* ------------------------------------------------------ public */

    // Credential exchange. Throttled by the named limiter configured in
    // config/rate_limiting.php - 5 attempts per client IP per minute. Keyed
    // on IP rather than email so an attacker cannot lock a known account out
    // by hammering it; a single address can still only guess so fast.
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Live as of Phase 12 — no 501, no enable flag. Delivery follows
    // MAIL_MAILER (see .env.example and config/auth.php); throttled by
    // password_reset so a script cannot spend a server's mail budget.
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware('throttle:password_reset');

    Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:password_reset');

    /* ------------------------------------------- bearer-token only */

    Route::middleware('auth:sanctum')->group(function () {

        // Who am I / sign out / change password.
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Device & session management foundation.
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession'])
            ->whereNumber('id');

        // Phase 12 additions to the same block, both for signing out in a
        // way a stale token cannot undo:
        //
        //   DELETE  /auth/sessions           EVERY session, including the
        //                                    one making the call. "Sign
        //                                    out everywhere" is one
        //                                    answer, not a parameter —
        //                                    see AuthController for why
        //                                    sparing the caller would make
        //                                    the safety property
        //                                    conditional.
        //   POST    /auth/sessions/revoke    present an OLD plaintext token
        //                                    and have it killed. This is
        //                                    what a client that has
        //                                    already lost its own session
        //                                    (offline logout, a reinstall)
        //                                    needs: the token is the
        //                                    credential, so holding it is
        //                                    what makes it yours to kill.
        //
        // The literal `revoke` is declared before `{id}` is even a question
        // — different verb, different path — and `{id}` stays `whereNumber`
        // so nothing else can be read as an id.
        Route::post('auth/sessions/revoke', [AuthController::class, 'revokeByToken']);
        Route::delete('auth/sessions', [AuthController::class, 'revokeAllSessions']);

        // Client-safe configuration: the currency a form should offer, and
        // the codes it may offer. No `permission:` on purpose — see
        // ClientSettingsController for why a non-secret value must not be
        // gated behind a right somebody has to be granted first.
        Route::get('client-settings', ClientSettingsController::class);

        // The Phase 3 proof that RBAC is enforced server-side: without
        // roles.view this 403s no matter what the Flutter UI chooses to show.
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('permission:roles.view');

        /* ------------------------------------------- Phase 4: modules */

        // Two gates, both live:
        //
        //   `permission:`  is the COARSE one — "may this role open the
        //                  module at all?". It runs before the controller,
        //                  so an unauthorised caller never reaches a query.
        //
        //   `$this->authorize()` resolves the policy, which is the ROW-LEVEL
        //                  one — "may they touch *this* record?". It runs on
        //                  every method, including the ones below that carry
        //                  no `permission:` middleware.
        //
        // Two routes deliberately omit the coarse gate. `GET /employees/{id}`
        // and `GET /employee-site-assignments/{id}` must stay reachable to
        // somebody reading their own row while holding no `*.view`
        // permission at all — the ordinary employee checking their own
        // profile. EmployeePolicy / EmployeeSiteAssignmentPolicy are what
        // separate "yours" from "everybody else's" there, and they answer
        // 403 for anything else.

        // Departments — master data, no row-level rules (DepartmentPolicy).
        Route::get('departments', [DepartmentController::class, 'index'])
            ->middleware('permission:departments.view');
        Route::get('departments/{department}', [DepartmentController::class, 'show'])
            ->middleware('permission:departments.view');
        Route::post('departments', [DepartmentController::class, 'store'])
            ->middleware('permission:departments.manage');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])
            ->middleware('permission:departments.manage');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])
            ->middleware('permission:departments.manage');

        // Designations — same shape, plus a department filter.
        Route::get('designations', [DesignationController::class, 'index'])
            ->middleware('permission:designations.view');
        Route::get('designations/{designation}', [DesignationController::class, 'show'])
            ->middleware('permission:designations.view');
        Route::post('designations', [DesignationController::class, 'store'])
            ->middleware('permission:designations.manage');
        Route::put('designations/{designation}', [DesignationController::class, 'update'])
            ->middleware('permission:designations.manage');
        Route::delete('designations/{designation}', [DesignationController::class, 'destroy'])
            ->middleware('permission:designations.manage');

        // Employees — see the note above for why `show` has no middleware.
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('permission:employees.view');
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::post('employees', [EmployeeController::class, 'store'])
            ->middleware('permission:employees.create')
            ->middleware('throttle:write');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:employees.update')
            ->middleware('throttle:write');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware('permission:employees.delete');

        // Projects.
        Route::get('projects', [ProjectController::class, 'index'])
            ->middleware('permission:projects.view');
        Route::get('projects/{project}', [ProjectController::class, 'show'])
            ->middleware('permission:projects.view');
        Route::post('projects', [ProjectController::class, 'store'])
            ->middleware('permission:projects.manage');
        Route::put('projects/{project}', [ProjectController::class, 'update'])
            ->middleware('permission:projects.manage');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])
            ->middleware('permission:projects.manage');

        // Sites.
        Route::get('sites', [SiteController::class, 'index'])
            ->middleware('permission:sites.view');
        Route::get('sites/{site}', [SiteController::class, 'show'])
            ->middleware('permission:sites.view');
        Route::post('sites', [SiteController::class, 'store'])
            ->middleware('permission:sites.manage');
        Route::put('sites/{site}', [SiteController::class, 'update'])
            ->middleware('permission:sites.manage');
        Route::delete('sites/{site}', [SiteController::class, 'destroy'])
            ->middleware('permission:sites.manage');

        // Employee site assignments — READ, CREATE and CLOSE only. No DELETE
        // route exists: posting history is append-only, and an endpoint that
        // erased it would be reachable by anyone holding assignments.manage,
        // which is not the same thing as being allowed to rewrite the past.
        Route::get('employee-site-assignments', [EmployeeSiteAssignmentController::class, 'index'])
            ->middleware('permission:assignments.view');
        Route::get('employee-site-assignments/{assignment}', [EmployeeSiteAssignmentController::class, 'show']);
        Route::post('employee-site-assignments', [EmployeeSiteAssignmentController::class, 'store'])
            ->middleware('permission:assignments.manage');
        Route::put('employee-site-assignments/{assignment}', [EmployeeSiteAssignmentController::class, 'update'])
            ->middleware('permission:assignments.manage');

        /* ----------------------------------------- Phase 5: attendance */

        // Four routes below deliberately carry NO `permission:` middleware,
        // and the reason is a real product decision rather than an omission:
        //
        //   GET  /attendance/today         the employee's own front door
        //   POST /attendance/check-in      recording your own day is not a
        //   POST /attendance/check-out     privilege anybody grants you
        //   GET  /site-visits/today        likewise
        //   POST /site-visits/start, /end  likewise
        //
        // AttendancePolicy and SiteVisitPolicy answer "is this a linked
        // employee?" for those, and the *row scope* for everything that
        // reads somebody else's record — where Visibility fails closed, so
        // holding `attendance.view` grants your own rows and no others
        // unless you are also trusted with the workforce.
        //
        // `throttle:attendance` is keyed by user, not IP: a site office
        // shares one address and one stuck device must not throttle the
        // whole crew (config/rate_limiting.php).

        Route::get('attendance/today', [AttendanceController::class, 'today']);

        Route::get('attendance', [AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view');

        // Registered before `{attendance}` and restricted to digits so
        // `today`, `check-in` and `check-out` can never be read as an id.
        Route::get('attendance/{attendance}/selfie', [AttendanceController::class, 'selfie'])
            ->whereNumber('attendance');
        Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])
            ->whereNumber('attendance');

        Route::post('attendance/check-in', [AttendanceController::class, 'checkIn'])
            ->middleware('throttle:attendance');
        Route::post('attendance/check-out', [AttendanceController::class, 'checkOut'])
            ->middleware('throttle:attendance');

        Route::get('site-visits/today', [SiteVisitController::class, 'today']);
        Route::get('site-visits', [SiteVisitController::class, 'index'])
            ->middleware('permission:attendance.view');
        Route::post('site-visits/start', [SiteVisitController::class, 'store'])
            ->middleware('throttle:attendance');
        Route::post('site-visits/{siteVisit}/end', [SiteVisitController::class, 'end'])
            ->whereNumber('siteVisit')
            ->middleware('throttle:attendance');

        // The day as one ordered list. Self-only, and no permission gate —
        // see MovementController.
        Route::get('movement/today', [MovementController::class, 'today']);

        /* ------------------------------------------ Phase 6: leave & co */

        // Same two-gate arrangement as Phase 4 and Phase 5: `permission:` on
        // the route is the coarse door, the policy is the row, and the
        // service is the state machine.
        //
        // Three routes deliberately carry no `permission:` middleware, and
        // each for the reason the Phase 5 self-service routes carry none:
        //
        //   GET  /holidays                 a day the company declared off is
        //                                 not a privilege within it — see
        //                                 HolidayPolicy
        //   POST /leave/{id}/certificate   filing your own medical note is
        //   GET  /leave/{id}/certificate   as intrinsic to your own leave as
        //                                 checking in is to your own day
        //
        // Literals are registered before `{...}` bindings, and every binding
        // is `whereNumber`, so `generate`, `check-in` and friends can never be
        // read as an id.

        // Leave types — policy configuration (LeaveTypePolicy).
        Route::get('leave-types', [LeaveTypeController::class, 'index'])
            ->middleware('permission:leave.view');
        Route::get('leave-types/{leaveType}', [LeaveTypeController::class, 'show'])
            ->whereNumber('leaveType')
            ->middleware('permission:leave.view');
        Route::post('leave-types', [LeaveTypeController::class, 'store'])
            ->middleware('permission:leave.manage');
        Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])
            ->whereNumber('leaveType')
            ->middleware('permission:leave.manage');
        Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])
            ->whereNumber('leaveType')
            ->middleware('permission:leave.manage');

        // Leave balances — read with leave, write with HR.
        Route::get('leave-balances', [LeaveBalanceController::class, 'index'])
            ->middleware('permission:leave.balance.view');
        Route::get('leave-balances/{leaveBalance}', [LeaveBalanceController::class, 'show'])
            ->whereNumber('leaveBalance')
            ->middleware('permission:leave.balance.view');
        Route::put('leave-balances/{leaveBalance}', [LeaveBalanceController::class, 'update'])
            ->whereNumber('leaveBalance')
            ->middleware('permission:leave.balance.manage');

        // Leave requests.
        Route::get('leave', [LeaveRequestController::class, 'index'])
            ->middleware('permission:leave.view');

        Route::post('leave/{leaveRequest}/certificate', [LeaveRequestController::class, 'storeCertificate'])
            ->whereNumber('leaveRequest')
            ->middleware('throttle:upload');
        Route::get('leave/{leaveRequest}/certificate', [LeaveRequestController::class, 'certificate'])
            ->whereNumber('leaveRequest');

        Route::post('leave/{leaveRequest}/submit', [LeaveRequestController::class, 'submit'])
            ->whereNumber('leaveRequest')
            ->middleware('permission:leave.create');
        Route::post('leave/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])
            ->whereNumber('leaveRequest')
            ->middleware('permission:leave.approve');
        Route::post('leave/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])
            ->whereNumber('leaveRequest')
            ->middleware('permission:leave.approve');
        Route::post('leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])
            ->whereNumber('leaveRequest')
            ->middleware('permission:leave.create');

        Route::get('leave/{leaveRequest}', [LeaveRequestController::class, 'show'])
            ->whereNumber('leaveRequest')
            ->middleware('permission:leave.view');
        Route::post('leave', [LeaveRequestController::class, 'store'])
            ->middleware('permission:leave.create');
        Route::put('leave/{leaveRequest}', [LeaveRequestController::class, 'update'])
            ->whereNumber('leaveRequest')
            ->middleware('permission:leave.create');

        // Holidays — readable by every signed-in account.
        Route::get('holidays', [HolidayController::class, 'index']);
        Route::get('holidays/{holiday}', [HolidayController::class, 'show'])
            ->whereNumber('holiday');
        Route::post('holidays', [HolidayController::class, 'store'])
            ->middleware('permission:holidays.manage');
        Route::put('holidays/{holiday}', [HolidayController::class, 'update'])
            ->whereNumber('holiday')
            ->middleware('permission:holidays.manage');

        // Approval chains — the configuration of who may say yes to what.
        Route::get('approval-workflows', [ApprovalWorkflowController::class, 'index'])
            ->middleware('permission:approvals.view');
        Route::get('approval-workflows/{approvalWorkflow}', [ApprovalWorkflowController::class, 'show'])
            ->whereNumber('approvalWorkflow')
            ->middleware('permission:approvals.view');
        Route::post('approval-workflows', [ApprovalWorkflowController::class, 'store'])
            ->middleware('permission:approvals.manage');
        Route::put('approval-workflows/{approvalWorkflow}', [ApprovalWorkflowController::class, 'update'])
            ->whereNumber('approvalWorkflow')
            ->middleware('permission:approvals.manage');

        // Timesheets. `generate` is a literal and is registered first, so it
        // can never be swallowed by the `{timesheet}` binding below.
        Route::get('timesheets', [TimesheetController::class, 'index'])
            ->middleware('permission:timesheets.view');
        Route::post('timesheets/generate', [TimesheetController::class, 'generate'])
            ->middleware('permission:timesheets.manage');
        Route::get('timesheets/{timesheet}', [TimesheetController::class, 'show'])
            ->whereNumber('timesheet')
            ->middleware('permission:timesheets.view');

        // Overtime — the same eight verbs the leave routes expose.
        Route::get('overtime', [OvertimeController::class, 'index'])
            ->middleware('permission:overtime.view');
        Route::post('overtime/{overtimeRequest}/submit', [OvertimeController::class, 'submit'])
            ->whereNumber('overtimeRequest')
            ->middleware('permission:overtime.create');
        Route::post('overtime/{overtimeRequest}/approve', [OvertimeController::class, 'approve'])
            ->whereNumber('overtimeRequest')
            ->middleware('permission:overtime.approve');
        Route::post('overtime/{overtimeRequest}/reject', [OvertimeController::class, 'reject'])
            ->whereNumber('overtimeRequest')
            ->middleware('permission:overtime.approve');
        Route::post('overtime/{overtimeRequest}/cancel', [OvertimeController::class, 'cancel'])
            ->whereNumber('overtimeRequest')
            ->middleware('permission:overtime.create');
        Route::get('overtime/{overtimeRequest}', [OvertimeController::class, 'show'])
            ->whereNumber('overtimeRequest')
            ->middleware('permission:overtime.view');
        Route::post('overtime', [OvertimeController::class, 'store'])
            ->middleware('permission:overtime.create');
        Route::put('overtime/{overtimeRequest}', [OvertimeController::class, 'update'])
            ->whereNumber('overtimeRequest')
            ->middleware('permission:overtime.create');

        /* ----------------------------------------- Phase 7: site reporting */

        // Two modules, two coarse gates, and both are narrower than they
        // look:
        //
        //   `site_activity_reports.*`  a person's own note about a site-day.
        //                              Held by every account expected to
        //                              stand on a site; Visibility then
        //                              narrows the rows to the sites that
        //                              account is actually attached to.
        //
        //   `daily_site_reports.*`     the official, unique-per-site-per-date
        //                              record. Not held by Employee at all —
        //                              two official reports for one day is a
        //                              contradiction nobody can resolve.
        //
        // Sub-routes (`/photos`, `/submit`, `/pdf`) are registered before
        // `/{report}` in every case; the `{report}` routes are also
        // `whereNumber`, so a literal could not be swallowed by them even
        // with the ordering reversed. Belt and braces is cheap here because
        // a mis-bound route would silently route a request to the wrong
        // controller rather than 404.

        // --- site activity reports -----------------------------------
        Route::get('site-activity-reports', [SiteActivityReportController::class, 'index'])
            ->middleware('permission:site_activity_reports.view');

        // The answer to "which sites may I write about?" — asked because
        // `GET /sites` needs `sites.view`, which no Employee holds, and the
        // field-reporting form exists precisely for Employees.
        Route::get('site-activity-reports/reportable-sites', [SiteActivityReportController::class, 'reportableSites'])
            ->middleware('permission:site_activity_reports.view');

        Route::get('site-activity-reports/{siteActivityReport}/photos/{photo}', [SiteActivityReportController::class, 'showPhoto'])
            ->whereNumber('siteActivityReport')
            ->whereNumber('photo')
            ->middleware('permission:site_activity_reports.view');

        Route::post('site-activity-reports/{siteActivityReport}/photos', [SiteActivityReportController::class, 'storePhotos'])
            ->whereNumber('siteActivityReport')
            ->middleware('permission:site_activity_reports.update')
            ->middleware('throttle:upload');

        Route::delete('site-activity-reports/{siteActivityReport}/photos/{photo}', [SiteActivityReportController::class, 'destroyPhoto'])
            ->whereNumber('siteActivityReport')
            ->whereNumber('photo')
            ->middleware('permission:site_activity_reports.update');

        Route::post('site-activity-reports/{siteActivityReport}/submit', [SiteActivityReportController::class, 'submit'])
            ->whereNumber('siteActivityReport')
            ->middleware('permission:site_activity_reports.update');

        Route::get('site-activity-reports/{siteActivityReport}', [SiteActivityReportController::class, 'show'])
            ->whereNumber('siteActivityReport')
            ->middleware('permission:site_activity_reports.view');

        Route::put('site-activity-reports/{siteActivityReport}', [SiteActivityReportController::class, 'update'])
            ->whereNumber('siteActivityReport')
            ->middleware('permission:site_activity_reports.update');

        Route::post('site-activity-reports', [SiteActivityReportController::class, 'store'])
            ->middleware('permission:site_activity_reports.create');

        // --- daily site reports --------------------------------------
        Route::get('daily-site-reports', [DailySiteReportController::class, 'index'])
            ->middleware('permission:daily_site_reports.view');

        Route::get('daily-site-reports/{dailySiteReport}/photos/{photo}', [DailySiteReportController::class, 'showPhoto'])
            ->whereNumber('dailySiteReport')
            ->whereNumber('photo')
            ->middleware('permission:daily_site_reports.view');

        Route::post('daily-site-reports/{dailySiteReport}/photos', [DailySiteReportController::class, 'storePhotos'])
            ->whereNumber('dailySiteReport')
            ->middleware('permission:daily_site_reports.update')
            ->middleware('throttle:upload');

        Route::delete('daily-site-reports/{dailySiteReport}/photos/{photo}', [DailySiteReportController::class, 'destroyPhoto'])
            ->whereNumber('dailySiteReport')
            ->whereNumber('photo')
            ->middleware('permission:daily_site_reports.update');

        Route::post('daily-site-reports/{dailySiteReport}/submit', [DailySiteReportController::class, 'submit'])
            ->whereNumber('dailySiteReport')
            ->middleware('permission:daily_site_reports.update');

        // Its own permission rather than reusing `.view`: reading the
        // numbers and being handed a document you can forward are
        // different acts, and DailySiteReportPolicy::pdf() asks both this
        // and the row-level question.
        Route::get('daily-site-reports/{dailySiteReport}/pdf', [DailySiteReportController::class, 'pdf'])
            ->whereNumber('dailySiteReport')
            ->middleware('permission:daily_site_reports.pdf');

        Route::get('daily-site-reports/{dailySiteReport}', [DailySiteReportController::class, 'show'])
            ->whereNumber('dailySiteReport')
            ->middleware('permission:daily_site_reports.view');

        Route::put('daily-site-reports/{dailySiteReport}', [DailySiteReportController::class, 'update'])
            ->whereNumber('dailySiteReport')
            ->middleware('permission:daily_site_reports.update');

        Route::post('daily-site-reports', [DailySiteReportController::class, 'store'])
            ->middleware('permission:daily_site_reports.create');

        /* --------------------------------------- Phase 8: payroll, loans... */

        // Four coarse gates, one per thing a person might actually be
        // granted, and the row-level answer always comes from the policy
        // rather than from the middleware:
        //
        //   `payroll.view`             the payroll module - and, on its own,
        //                              narrowed to your own row.
        //   `payroll.process`          run a month, re-run one row.
        //   `payroll.manage`           correct the inputs, review, finalize.
        //   `payroll.lock`             the one irreversible button.
        //   `payroll.summary.view`     company totals with no rows behind
        //                              them - deliberately NOT a branch of
        //                              `payroll.view`, because a role that
        //                              may see the totals and a role that
        //                              may see the list are two roles.
        //
        // `salary_slips.*`, `salary_certificates.*` and `loans.*` are
        // separate families rather than sub-grants of payroll, so an
        // Employee can hold the documents without holding the ledger and a
        // Payroll Admin can hold the ledger without holding certificates.

        // --- payroll ----------------------------------------------------
        // `/summary` is registered before `/{payroll}`. It is also
        // `whereNumber` on the dynamic half, so a literal could not be
        // swallowed by it either way round - the same belt-and-braces as
        // Phase 7, and for the same reason: a mis-bound route sends a
        // request to the wrong controller instead of 404-ing.
        Route::get('payroll', [PayrollController::class, 'index'])
            ->middleware('permission:payroll.view');

        Route::get('payroll/summary', [PayrollController::class, 'summary'])
            ->middleware('permission:payroll.summary.view');

        Route::post('payroll/process', [PayrollController::class, 'process'])
            ->middleware('permission:payroll.process')
            ->middleware('throttle:write');

        Route::post('payroll/{payroll}/recalculate', [PayrollController::class, 'recalculate'])
            ->whereNumber('payroll')
            ->middleware('permission:payroll.process');

        Route::post('payroll/{payroll}/review', [PayrollController::class, 'review'])
            ->whereNumber('payroll')
            ->middleware('permission:payroll.manage');

        Route::post('payroll/{payroll}/finalize', [PayrollController::class, 'finalize'])
            ->whereNumber('payroll')
            ->middleware('permission:payroll.manage');

        Route::post('payroll/{payroll}/lock', [PayrollController::class, 'lock'])
            ->whereNumber('payroll')
            ->middleware('permission:payroll.lock');

        Route::get('payroll/{payroll}', [PayrollController::class, 'show'])
            ->whereNumber('payroll')
            ->middleware('permission:payroll.view');

        // --- salary slips -----------------------------------------------
        // `salary_slips.view` on both, not `payroll.view` - the pair exists
        // so a role can be given payslips without being given the payroll
        // module, and collapsing them would make one of the two grants
        // meaningless.
        Route::get('salary-slips', [SalarySlipController::class, 'index'])
            ->middleware('permission:salary_slips.view');

        Route::get('salary-slips/{payroll}/pdf', [SalarySlipController::class, 'pdf'])
            ->whereNumber('payroll')
            ->middleware('permission:salary_slips.view');

        // --- allowances --------------------------------------------------
        // `payroll.view` to read (narrowed to your own rows), `payroll.manage`
        // for every write - including your own.
        Route::get('allowances', [AllowanceController::class, 'index'])
            ->middleware('permission:payroll.view');

        Route::post('allowances', [AllowanceController::class, 'store'])
            ->middleware('permission:payroll.manage');

        Route::put('allowances/{allowance}', [AllowanceController::class, 'update'])
            ->whereNumber('allowance')
            ->middleware('permission:payroll.manage');

        Route::delete('allowances/{allowance}', [AllowanceController::class, 'destroy'])
            ->whereNumber('allowance')
            ->middleware('permission:payroll.manage');

        // --- payroll adjustments (bonuses, other deductions) -------------
        // Reads narrow to your own rows unless `payroll.manage`; every write
        // needs `payroll.manage`, including for the employee the adjustment
        // is about.
        Route::get('payroll-adjustments', [PayrollAdjustmentController::class, 'index'])
            ->middleware('permission:payroll.view');

        Route::post('payroll-adjustments', [PayrollAdjustmentController::class, 'store'])
            ->middleware('permission:payroll.manage');

        Route::post('payroll-adjustments/{adjustment}/approve', [PayrollAdjustmentController::class, 'approve'])
            ->whereNumber('adjustment')
            ->middleware('permission:payroll.manage');

        Route::post('payroll-adjustments/{adjustment}/reject', [PayrollAdjustmentController::class, 'reject'])
            ->whereNumber('adjustment')
            ->middleware('permission:payroll.manage');

        Route::post('payroll-adjustments/{adjustment}/cancel', [PayrollAdjustmentController::class, 'cancel'])
            ->whereNumber('adjustment')
            ->middleware('permission:payroll.manage');

        Route::put('payroll-adjustments/{adjustment}', [PayrollAdjustmentController::class, 'update'])
            ->whereNumber('adjustment')
            ->middleware('permission:payroll.manage');

        Route::get('payroll-adjustments/{adjustment}', [PayrollAdjustmentController::class, 'show'])
            ->whereNumber('adjustment')
            ->middleware('permission:payroll.view');

        // --- loans and salary advances -----------------------------------
        // Sub-routes first, `{loan}` second, and `{loan}` is `whereNumber` -
        // the Phase 7 ordering rule, repeated because a `POST /loans/approve`
        // that bound `approve` as a loan id would 404 into a 409 instead of
        // reaching the handler.
        Route::get('loans', [LoanController::class, 'index'])
            ->middleware('permission:loans.view');

        Route::post('loans', [LoanController::class, 'store'])
            ->middleware('permission:loans.create');

        Route::post('loans/{loan}/submit', [LoanController::class, 'submit'])
            ->whereNumber('loan')
            ->middleware('permission:loans.create');

        Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])
            ->whereNumber('loan')
            ->middleware('permission:loans.approve');

        Route::post('loans/{loan}/reject', [LoanController::class, 'reject'])
            ->whereNumber('loan')
            ->middleware('permission:loans.approve');

        Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])
            ->whereNumber('loan')
            ->middleware('permission:loans.view');

        Route::get('loans/{loan}', [LoanController::class, 'show'])
            ->whereNumber('loan')
            ->middleware('permission:loans.view');

        Route::put('loans/{loan}', [LoanController::class, 'update'])
            ->whereNumber('loan')
            ->middleware('permission:loans.view');

        // --- salary certificate requests ---------------------------------
        // `salary_certificates.view` is enough to *ask*, because an employee
        // cannot be granted a permission to request a document about their
        // own salary that they are then refused for exercising. `manage` is
        // what decides.
        Route::get('salary-certificate-requests', [SalaryCertificateRequestController::class, 'index'])
            ->middleware('permission:salary_certificates.view');

        Route::post('salary-certificate-requests', [SalaryCertificateRequestController::class, 'store'])
            ->middleware('permission:salary_certificates.view');

        Route::post('salary-certificate-requests/{salaryCertificateRequest}/approve', [SalaryCertificateRequestController::class, 'approve'])
            ->whereNumber('salaryCertificateRequest')
            ->middleware('permission:salary_certificates.manage');

        Route::post('salary-certificate-requests/{salaryCertificateRequest}/reject', [SalaryCertificateRequestController::class, 'reject'])
            ->whereNumber('salaryCertificateRequest')
            ->middleware('permission:salary_certificates.manage');

        Route::post('salary-certificate-requests/{salaryCertificateRequest}/cancel', [SalaryCertificateRequestController::class, 'cancel'])
            ->whereNumber('salaryCertificateRequest')
            ->middleware('permission:salary_certificates.view');

        Route::get('salary-certificate-requests/{salaryCertificateRequest}/pdf', [SalaryCertificateRequestController::class, 'pdf'])
            ->whereNumber('salaryCertificateRequest')
            ->middleware('permission:salary_certificates.view');

        Route::get('salary-certificate-requests/{salaryCertificateRequest}', [SalaryCertificateRequestController::class, 'show'])
            ->whereNumber('salaryCertificateRequest')
            ->middleware('permission:salary_certificates.view');

        /* ----------------------------------------- Phase 9: expenses */

        // Ordering, once more (the Phase 7 rule): `expenses/summary` is
        // declared before `expenses/{expense}` because Laravel matches in
        // declaration order and `/summary` would otherwise be swallowed as
        // an id — which surfaces not here but as a 404 with no explanation.
        Route::get('expenses', [ExpenseController::class, 'index'])
            ->middleware('permission:expenses.view');

        Route::get('expenses/summary', [ExpenseController::class, 'summary'])
            ->middleware('permission:expenses.view');

        // Reference data for the form. Read-only: a category is a row an
        // operator seeds, not a resource this API creates.
        Route::get('expense-categories', [ExpenseController::class, 'categories'])
            ->middleware('permission:expenses.view');

        Route::post('expenses', [ExpenseController::class, 'store'])
            ->middleware('permission:expenses.create')
            ->middleware('throttle:write');

        // `expenses.create` for the three an author drives (submit, cancel,
        // file) and `expenses.update` for the edit — the same split leave
        // uses, because filing and correcting are the same act while
        // signing off is a different one that needs `expenses.approve`.
        //
        // Receipts are gated with `expenses.view`, not with
        // `expenses.receipts.view`: the coarse gate has to admit the person
        // who *filed* the receipt, and ExpensePolicy then asks the real
        // question — your own claim's evidence, or somebody else's with
        // `expenses.receipts.view` on top of a claim you may already read.
        Route::post('expenses/{expense}/receipts', [ExpenseController::class, 'storeReceipts'])
            ->whereNumber('expense')
            ->middleware('permission:expenses.view')
            ->middleware('throttle:upload');

        Route::get('expenses/{expense}/receipts/{receipt}', [ExpenseController::class, 'receipt'])
            ->whereNumber('expense')
            ->whereNumber('receipt')
            ->middleware('permission:expenses.view');

        Route::delete('expenses/{expense}/receipts/{receipt}', [ExpenseController::class, 'deleteReceipt'])
            ->whereNumber('expense')
            ->whereNumber('receipt')
            ->middleware('permission:expenses.view');

        Route::post('expenses/{expense}/submit', [ExpenseController::class, 'submit'])
            ->whereNumber('expense')
            ->middleware('permission:expenses.create')
            ->middleware('throttle:write');

        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])
            ->whereNumber('expense')
            ->middleware('permission:expenses.approve')
            ->middleware('throttle:write');

        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])
            ->whereNumber('expense')
            ->middleware('permission:expenses.approve');

        Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])
            ->whereNumber('expense')
            ->middleware('permission:expenses.create');

        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])
            ->whereNumber('expense')
            ->middleware('permission:expenses.view');

        Route::put('expenses/{expense}', [ExpenseController::class, 'update'])
            ->whereNumber('expense')
            ->middleware('permission:expenses.update');

        /* ----------------------------------- Phase 10: documents, onboarding */

        // Four coarse gates across the whole module, and the row-level
        // answer always from EmployeeDocumentPolicy / EmployeeOnboardingPolicy
        // rather than from the middleware:
        //
        //   `documents.view`        read the catalogue and the list; Visibility
        //                           then narrows every row to your own unless
        //                           `documents.manage` is held too.
        //   `documents.create`      file — your own, or anybody's with
        //                           `documents.manage` (Visibility's
        //                           mayFileDocumentsFor, asked inside the
        //                           FormRequest where the target is known).
        //   `documents.update`      correct what is on file, again row-scoped.
        //   `documents.verify`      the only route that changes an
        //                           *accepted* state; refused for your own
        //                           document in every case.
        //   `documents.delete`      archive. Nothing is removed.
        //   `documents.expiry.view` the cross-employee "what is about to
        //                           lapse" report — its own permission
        //                           because the question is about everybody.
        //   `onboarding.view`       read the directory of where people stand.
        //   `onboarding.manage`     move the stage, complete the record.
        //
        // `GET employees/{employee}/bank-account` and its PUT deliberately
        // carry **no `permission:` middleware at all** — the same door
        // `GET employees/{employee}` has — because EmployeePolicy has to be
        // able to admit an employee reading *their own* account, and no
        // shared middleware can say "yours, or two grants".

        // --- document types --------------------------------------------
        // Reference data for the upload form. Gated by `documents.view`
        // rather than `employees.view`, so an ordinary employee can reach
        // the picker they are meant to attach their own passport through.
        Route::get('document-types', [DocumentTypeController::class, 'index'])
            ->middleware('permission:documents.view');

        // --- employee documents ----------------------------------------
        // `expiring` before `{document}` — the Phase 7 ordering rule, and
        // `whereNumber` on the dynamic half so a literal could not be
        // swallowed by it either way round.
        Route::get('employee-documents/expiring', [EmployeeDocumentController::class, 'expiring'])
            ->middleware('permission:documents.expiry.view');

        Route::get('employee-documents', [EmployeeDocumentController::class, 'index'])
            ->middleware('permission:documents.view');

        Route::post('employee-documents', [EmployeeDocumentController::class, 'store'])
            ->middleware('permission:documents.create')
            ->middleware('throttle:upload');

        Route::post('employee-documents/{document}/verify', [EmployeeDocumentController::class, 'verify'])
            ->whereNumber('document')
            ->middleware('permission:documents.verify');

        Route::post('employee-documents/{document}/reject', [EmployeeDocumentController::class, 'reject'])
            ->whereNumber('document')
            ->middleware('permission:documents.verify');

        // The bytes, and the only route to them. `documents.view` is the
        // coarse door; EmployeeDocumentPolicy::viewFile is the real one —
        // the same row scope as the record beside it, because the file *is*
        // the disclosure.
        Route::get('employee-documents/{document}/file', [EmployeeDocumentController::class, 'file'])
            ->whereNumber('document')
            ->middleware('permission:documents.view');

        Route::get('employee-documents/{document}', [EmployeeDocumentController::class, 'show'])
            ->whereNumber('document')
            ->middleware('permission:documents.view');

        Route::put('employee-documents/{document}', [EmployeeDocumentController::class, 'update'])
            ->whereNumber('document')
            ->middleware('permission:documents.update')
            ->middleware('throttle:upload');

        Route::delete('employee-documents/{document}', [EmployeeDocumentController::class, 'destroy'])
            ->whereNumber('document')
            ->middleware('permission:documents.delete');

        // --- onboarding -------------------------------------------------
        // `{employee}` rather than `{onboarding}`: the question a caller
        // asks is "where does she stand?", and EmployeeOnboardingPolicy is resolved
        // from an EmployeeOnboarding the controller stages unsaved where none
        // exists yet — a GET must not materialise a row.
        Route::get('onboarding', [OnboardingController::class, 'index'])
            ->middleware('permission:onboarding.view');

        Route::get('onboarding/{employee}', [OnboardingController::class, 'show'])
            ->middleware('permission:onboarding.view');

        Route::put('onboarding/{employee}', [OnboardingController::class, 'update'])
            ->middleware('permission:onboarding.manage');

        Route::post('onboarding/{employee}/complete', [OnboardingController::class, 'complete'])
            ->middleware('permission:onboarding.manage');

        // --- bank account -----------------------------------------------
        // Two routes, no coarse gate — see the block at the top of this
        // section for why one cannot exist here.
        Route::get('employees/{employee}/bank-account', [EmployeeBankAccountController::class, 'show']);

        Route::put('employees/{employee}/bank-account', [EmployeeBankAccountController::class, 'update']);

        /* ------------------------------------ Phase 11: training, assets */

        // Two new modules, twelve coarse gates across them, and the
        // row-level answer always from the policy rather than from the
        // middleware — the same split documents, leave and expenses keep.
        //
        //   training              `training.view`        read the catalogue and
        //                                               the list; Visibility
        //                                               narrows every row to your
        //                                               own unless
        //                                               `training.manage` too.
        //                       `training.create`      add a program.
        //                       `training.update`      correct one; also the gate
        //                                               on cancelling an enrolment.
        //                       `training.manage`      the ONLY door onto a
        //                                               colleague's course record.
        //                       `training.assign`      put somebody on a course —
        //                                               deliberately with no
        //                                               self-service half.
        //                       `training.complete`    record a pass, and the gate
        //                                               on the certificate upload.
        //                       `training.certificates.view`
        //                                               a colleague's certificate
        //                                               FILE. Your own needs only
        //                                               `training.view`, so
        //                                               `training.manage` does not
        //                                               open it either.
        //                       `training.expiry.view` the cross-employee "whose
        //                                               card is about to lapse"
        //                                               report.
        //
        //   assets                `assets.view`          read the register; without
        //                                               `assets.manage` Visibility
        //                                               narrows it to the assets
        //                                               you have actually been
        //                                               handed, current and past.
        //                       `assets.create`        add to the register.
        //                       `assets.update`        correct the master record.
        //                       `assets.manage`        status control, the row-scope
        //                                               door, and the door on
        //                                               `purchase_cost`.
        //                       `assets.assign`        hand one out.
        //                       `assets.return`        take one back — split from
        //                                               `assign` because in practice
        //                                               different people do them.
        //                       `assets.history.view`  the cross-employee
        //                                               hand-over log.

        // --- training types --------------------------------------------
        // The vocabulary behind the picker, unpaginated and unscoped: a
        // training type describes no person, so the whole table is one
        // answer for everybody who may open the training screen at all.
        Route::get('training-types', [TrainingTypeController::class, 'index'])
            ->middleware('permission:training.view');

        // --- training programs -----------------------------------------
        // The catalogue. No row scope anywhere in this group — a program is
        // configuration, not a record about a person — and no DELETE,
        // because a cohort that ran cannot be un-run. Retire instead.
        Route::get('training-programs', [TrainingProgramController::class, 'index'])
            ->middleware('permission:training.view');

        Route::post('training-programs', [TrainingProgramController::class, 'store'])
            ->middleware('permission:training.create');

        Route::get('training-programs/{program}', [TrainingProgramController::class, 'show'])
            ->whereNumber('program')
            ->middleware('permission:training.view');

        Route::put('training-programs/{program}', [TrainingProgramController::class, 'update'])
            ->whereNumber('program')
            ->middleware('permission:training.update');

        // --- employee training -----------------------------------------
        // `expiring` before `{training}` — the Phase 7 ordering rule, and
        // `whereNumber` on the dynamic half so a literal could not be
        // swallowed by it either way round.
        Route::get('employee-training/expiring', [EmployeeTrainingController::class, 'expiring'])
            ->middleware('permission:training.expiry.view');

        Route::get('employee-training', [EmployeeTrainingController::class, 'index'])
            ->middleware('permission:training.view');

        Route::post('employee-training', [EmployeeTrainingController::class, 'store'])
            ->middleware('permission:training.assign')
            ->middleware('throttle:write');

        // Two POSTs rather than a PUT with a `status`: each is a distinct
        // act with its own permission (`.complete` vs `.update`) and its
        // own refusal, and a `status` field in a payload is a second way to
        // say the same thing and a first way to say a different one.
        Route::post('employee-training/{training}/complete', [EmployeeTrainingController::class, 'complete'])
            ->whereNumber('training')
            ->middleware('permission:training.complete')
            ->middleware('throttle:upload');

        Route::post('employee-training/{training}/cancel', [EmployeeTrainingController::class, 'cancel'])
            ->whereNumber('training')
            ->middleware('permission:training.update');

        // The bytes, and the only route to them. `training.view` is the
        // coarse door; EmployeeTrainingPolicy::viewCertificate is the real
        // one — your own card, or `training.certificates.view` — and it is
        // deliberately NOT the same answer as the row beside it.
        Route::get('employee-training/{training}/file', [EmployeeTrainingController::class, 'file'])
            ->whereNumber('training')
            ->middleware('permission:training.view');

        Route::get('employee-training/{training}', [EmployeeTrainingController::class, 'show'])
            ->whereNumber('training')
            ->middleware('permission:training.view');

        Route::put('employee-training/{training}', [EmployeeTrainingController::class, 'update'])
            ->whereNumber('training')
            ->middleware('permission:training.update');

        // The compliance summary sits at the top level rather than under
        // `employee-training/`, because it answers about the *workforce*
        // and its own URL keeps `employee-training/{training}` unambiguous
        // for a reader skimming a log of calls.
        Route::get('training-compliance', [EmployeeTrainingController::class, 'compliance'])
            ->middleware('permission:training.view');

        // --- asset types ------------------------------------------------
        // Same reasoning as training-types: the vocabulary behind the
        // picker, whole and unscoped.
        Route::get('asset-types', [AssetTypeController::class, 'index'])
            ->middleware('permission:assets.view');

        // --- assets -----------------------------------------------------
        // `{asset}` is `whereNumber` and `asset_code` — the barcode a
        // label will carry — is therefore NOT part of any URL. Codes are
        // human-chosen strings that may contain anything; ids are the
        // only thing that is safe to put in a route.
        Route::get('assets', [AssetController::class, 'index'])
            ->middleware('permission:assets.view');

        Route::post('assets', [AssetController::class, 'store'])
            ->middleware('permission:assets.create');

        Route::post('assets/{asset}/assign', [AssetController::class, 'assign'])
            ->whereNumber('asset')
            ->middleware('permission:assets.assign')
            ->middleware('throttle:write');

        Route::post('assets/{asset}/return', [AssetController::class, 'returnAsset'])
            ->whereNumber('asset')
            ->middleware('permission:assets.return')
            ->middleware('throttle:write');

        Route::patch('assets/{asset}/status', [AssetController::class, 'changeStatus'])
            ->whereNumber('asset')
            ->middleware('permission:assets.manage');

        Route::get('assets/{asset}', [AssetController::class, 'show'])
            ->whereNumber('asset')
            ->middleware('permission:assets.view');

        Route::put('assets/{asset}', [AssetController::class, 'update'])
            ->whereNumber('asset')
            ->middleware('permission:assets.update');

        // --- asset assignments ------------------------------------------
        // Read-only, and `assets.history.view` is the cross-employee door
        // on top of `assets.view`. Writing happens on the two routes
        // above, through AssetService, where the lock is.
        Route::get('asset-assignments', [AssetAssignmentController::class, 'index'])
            ->middleware('permission:assets.view');

        Route::get('asset-assignments/{assignment}', [AssetAssignmentController::class, 'show'])
            ->whereNumber('assignment')
            ->middleware('permission:assets.view');

        /* -------------------------------------- Phase 12: notifications */

        // The inbox carries **no `permission:` middleware anywhere**, and
        // the reason is not an omission: every row it returns was written
        // for this caller by NotificationService, so `auth:sanctum` is the
        // whole authorisation. A `notifications.view` grant would be a
        // lie — there is no body of notifications a person is forbidden to
        // see, only rows addressed to them, and the service filters by
        // `user_id` before anything reaches a response.
        //
        // `unread-count` is declared before `{notification}/read` — the
        // Phase 7 ordering rule — and both dynamic halves are `whereNumber`,
        // so neither can swallow the other either way round.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
            ->whereNumber('notification');

        // Preferences. Both routes are self-service for the same reason
        // the inbox is: the switch belongs to the person whose switches
        // they are. Mandatory categories are refused inside the controller
        // with a 409 — see NotificationPreferenceController.
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);

        // Device registration. `throttle:device_token` is on POST because
        // that is the one a script can loop — an upsert against a unique
        // index, followed by a queue job per notification. DELETE is
        // idempotent cleanup at logout and costs nothing to repeat.
        Route::post('device-tokens', [DeviceTokenController::class, 'store'])
            ->middleware('throttle:device_token');
        Route::delete('device-tokens/{deviceToken}', [DeviceTokenController::class, 'destroy'])
            ->whereNumber('deviceToken');

        /* ------------------------------------------- Phase 12: audit */

        // One coarse gate, `audit.view`, and the policy asks the same
        // question again from the controller. No row-level scoping exists
        // on purpose: an audit trail filtered to rows you could already
        // see is not an audit trail. See AuditLogController.
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');
        Route::get('audit-logs/options', [AuditLogController::class, 'options'])
            ->middleware('permission:audit.view');

        /* ---------------------------------------- Phase 12: dashboards */

        // Four role dashboards behind ONE coarse gate — `dashboard.view` —
        // because the gate answers "may this account open a dashboard at
        // all?", which is a single question. What each payload may contain
        // is decided inside DashboardController with explicit
        // multi-permission checks, so a Project Manager who holds
        // `dashboard.view` gets the blocks their other grants allow and
        // nothing about salary, while a Management account holding
        // `payroll.summary.view` gets the totals and still not one row of
        // them. Two mechanisms, each doing the half it can express: no
        // permission middleware combination can say "this endpoint, but
        // only these three of its four blocks".
        Route::get('dashboards/employee', [DashboardController::class, 'employee'])
            ->middleware('permission:dashboard.view');
        Route::get('dashboards/hr', [DashboardController::class, 'hr'])
            ->middleware('permission:dashboard.view');
        Route::get('dashboards/project-manager', [DashboardController::class, 'projectManager'])
            ->middleware('permission:dashboard.view');
        Route::get('dashboards/management', [DashboardController::class, 'management'])
            ->middleware('permission:dashboard.view');

        /* ------------------------------------------ Phase 12: reporting */

        // `GET  /reports`                    the catalogue this caller may run
        // `GET  /reports/{key}`              one report, filtered, paged
        // `GET  /reports/{key}/export`       a small report, streamed now
        // `POST /reports/{key}/exports`      a big one, queued, 202
        // `GET  /report-exports`             what is still building
        // `GET  /report-exports/{id}/file`   the finished file
        //
        // The route gate is the *coarse* answer (`reports.view` /
        // `reports.export`); every report then carries its own permission
        // in the registry — `payroll.view` for the salary report,
        // `attendance.view` for attendance — and ReportService re-checks it
        // after the path parameter, so a key typed into a URL can never
        // reach a report the caller's role does not hold. See
        // App\Services\Reporting\ReportRegistry.
        //
        // `{key}` is constrained to a safe slug rather than `whereNumber`:
        // it is a catalogue name from this application, never a number and
        // never free text, and narrowing the pattern means a stray path
        // segment 404s instead of being looked up.
        Route::get('reports', [ReportController::class, 'index'])
            ->middleware('permission:reports.view');

        Route::get('reports/{key}', [ReportController::class, 'show'])
            ->where('key', '[a-z0-9\-.]+')
            ->middleware('permission:reports.view');

        Route::get('reports/{key}/export', [ReportController::class, 'export'])
            ->where('key', '[a-z0-9\-.]+')
            ->middleware(['permission:reports.view', 'permission:reports.export', 'throttle:export']);

        Route::post('reports/{key}/exports', [ReportController::class, 'storeExport'])
            ->where('key', '[a-z0-9\-.]+')
            ->middleware(['permission:reports.view', 'permission:reports.export', 'throttle:export']);

        Route::get('report-exports', [ReportExportController::class, 'index'])
            ->middleware('permission:reports.view');

        Route::get('report-exports/{reportExport}/file', [ReportExportController::class, 'file'])
            ->whereNumber('reportExport')
            ->middleware('permission:reports.view');
    });
});
