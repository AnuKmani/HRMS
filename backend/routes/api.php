<?php

use App\Http\Controllers\Api\V1\ApprovalWorkflowController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DailySiteReportController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DesignationController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeSiteAssignmentController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\MovementController;
use App\Http\Controllers\Api\V1\OvertimeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SiteActivityReportController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\SiteVisitController;
use App\Http\Controllers\Api\V1\TimesheetController;
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

    // Built, validated and routed — but answered 501 until a real mailer is
    // configured and PASSWORD_RESET_ENABLED=true. See PasswordResetController.
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
            ->middleware('permission:employees.create');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:employees.update');
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
            ->whereNumber('leaveRequest');
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
            ->middleware('permission:site_activity_reports.update');

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
            ->middleware('permission:daily_site_reports.update');

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
    });
});
