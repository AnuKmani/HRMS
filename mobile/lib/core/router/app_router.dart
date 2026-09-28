import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/attendance/presentation/attendance_screen.dart';
import '../../features/auth/auth_controller.dart';
import '../../features/auth/auth_state.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/departments/presentation/department_form_screen.dart';
import '../../features/departments/presentation/departments_list_screen.dart';
import '../../features/designations/presentation/designation_form_screen.dart';
import '../../features/designations/presentation/designations_list_screen.dart';
import '../../features/employees/presentation/employee_detail_screen.dart';
import '../../features/employees/presentation/employee_form_screen.dart';
import '../../features/employees/presentation/employees_list_screen.dart';
import '../../features/home/home_screen.dart';
import '../../features/holidays/presentation/holiday_form_screen.dart';
import '../../features/holidays/presentation/holidays_list_screen.dart';
import '../../features/leave/presentation/leave_balance_screen.dart';
import '../../features/leave/presentation/leave_detail_screen.dart';
import '../../features/leave/presentation/leave_form_screen.dart';
import '../../features/leave/presentation/leave_list_screen.dart';
import '../../features/loans/presentation/loan_detail_screen.dart';
import '../../features/loans/presentation/loan_form_screen.dart';
import '../../features/loans/presentation/loan_list_screen.dart';
import '../../features/overtime/presentation/overtime_detail_screen.dart';
import '../../features/overtime/presentation/overtime_form_screen.dart';
import '../../features/overtime/presentation/overtime_list_screen.dart';
import '../../features/payroll/presentation/payroll_detail_screen.dart';
import '../../features/payroll/presentation/payroll_list_screen.dart';
import '../../features/payroll/presentation/salary_slips_screen.dart';
import '../../features/projects/presentation/project_detail_screen.dart';
import '../../features/projects/presentation/project_form_screen.dart';
import '../../features/projects/presentation/projects_list_screen.dart';
import '../../features/salary_certificates/presentation/salary_certificate_detail_screen.dart';
import '../../features/salary_certificates/presentation/salary_certificate_form_screen.dart';
import '../../features/salary_certificates/presentation/salary_certificate_list_screen.dart';
import '../../features/sites/presentation/site_detail_screen.dart';
import '../../features/sites/presentation/site_form_screen.dart';
import '../../features/sites/presentation/sites_list_screen.dart';
import '../../features/site_reports/presentation/daily_report_detail_screen.dart';
import '../../features/site_reports/presentation/daily_report_form_screen.dart';
import '../../features/site_reports/presentation/daily_report_list_screen.dart';
import '../../features/site_reports/presentation/site_activity_detail_screen.dart';
import '../../features/site_reports/presentation/site_activity_form_screen.dart';
import '../../features/site_reports/presentation/site_activity_list_screen.dart';
import '../../features/timesheet/presentation/timesheet_detail_screen.dart';
import '../../features/timesheet/presentation/timesheets_list_screen.dart';

/// The app's routes, one redirect, and a guard driven entirely by auth state.
///
/// Eight phases of work now sit behind it. Two rules shape how they are
/// laid out:
///
///  - **`new` before `:id`, and `:id` only matches digits.** A route
///    `'/employees/:id'` would otherwise swallow `/employees/new` and try to
///    parse it as a person. The `(\d+)` pattern is the belt; the ordering is
///    the braces.
///  - **No route guards anything on a permission.** Every screen behind one
///    asks `PermissionScope` whether to offer the way in, and every request
///    it makes is checked again by the API. A redirect that hid a URL would
///    be a second, weaker authorisation system to keep in step with the
///    first — so there isn't one; the redirect only ever answers "is there a
///    session?".
final routerProvider = Provider<GoRouter>((ref) {
  // GoRouter re-runs its redirect whenever this notifies.
  //
  // Bumping a plain notifier — rather than recreating the GoRouter itself —
  // is what keeps a sign-in from tearing down the route stack: rebuilding the
  // router would dispose the route currently on screen, and with it the
  // email and password the user had already typed.
  final authChanged = ValueNotifier<int>(0);

  ref.listen(authControllerProvider, (_, _) => authChanged.value++);

  /// The `:id` path parameter, as the integer the repositories expect.
  ///
  /// The `(\d+)` pattern on each route means this cannot throw for a
  /// well-formed URL; a malformed one never reaches the builder.
  int idOf(GoRouterState state) => int.parse(state.pathParameters['id']!);

  final router = GoRouter(
    initialLocation: '/',
    refreshListenable: authChanged,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final location = state.matchedLocation;

      // The answer is not known yet. Send everything to the splash screen
      // rather than guessing — otherwise a returning user gets shown the
      // sign-in form for the length of a single request, then bounced away.
      if (auth.status == AuthStatus.restoring) {
        return location == '/' ? null : '/';
      }

      final landing = location == '/' || location == '/login';

      if (auth.isAuthenticated) {
        // Also covers a deep link back to the landing pages, so a stale
        // notification cannot drop a signed-in user onto the sign-in form.
        return landing ? '/home' : null;
      }

      // Not a session — which covers `authenticating` and `failed` as well:
      // neither should read as signed in. Anything but the form itself goes
      // there, `/` included: the splash screen exists only while the answer
      // is pending, and by now it has arrived.
      return location == '/login' ? null : '/login';
    },
    routes: [
      GoRoute(path: '/', builder: (context, state) => const SplashScreen()),
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),
      GoRoute(path: '/home', builder: (context, state) => const HomeScreen()),

      /* ------------------------------------------------------ attendance */

      // The only Phase 5 route, and the only one without an `:id` form:
      // this screen is always and only the caller's own day, so there is
      // nothing to look up. Reachable by every signed-in user with an
      // employee record — checking yourself in is not a privilege — and the
      // API behind it answers 403 for anyone without one.
      GoRoute(
        path: '/attendance',
        builder: (context, state) => const AttendanceScreen(),
      ),

      /* ------------------------------------------------------ employees */
      GoRoute(
        path: '/employees',
        builder: (context, state) => const EmployeesListScreen(),
      ),
      GoRoute(
        path: '/employees/new',
        builder: (context, state) => const EmployeeFormScreen(),
      ),
      GoRoute(
        path: '/employees/:id',
        builder: (context, state) =>
            EmployeeDetailScreen(employeeId: idOf(state)),
      ),
      GoRoute(
        path: '/employees/:id/edit',
        builder: (context, state) =>
            EmployeeFormScreen(employeeId: idOf(state)),
      ),

      /* ---------------------------------------------------- departments */
      GoRoute(
        path: '/departments',
        builder: (context, state) => const DepartmentsListScreen(),
      ),
      GoRoute(
        path: '/departments/new',
        builder: (context, state) => const DepartmentFormScreen(),
      ),
      GoRoute(
        path: '/departments/:id',
        builder: (context, state) =>
            DepartmentFormScreen(departmentId: idOf(state)),
      ),

      /* --------------------------------------------------- designations */
      GoRoute(
        path: '/designations',
        builder: (context, state) => const DesignationsListScreen(),
      ),
      GoRoute(
        path: '/designations/new',
        builder: (context, state) => const DesignationFormScreen(),
      ),
      GoRoute(
        path: '/designations/:id',
        builder: (context, state) =>
            DesignationFormScreen(designationId: idOf(state)),
      ),

      /* ------------------------------------------------------- projects */
      GoRoute(
        path: '/projects',
        builder: (context, state) => const ProjectsListScreen(),
      ),
      GoRoute(
        path: '/projects/new',
        builder: (context, state) => const ProjectFormScreen(),
      ),
      GoRoute(
        path: '/projects/:id',
        builder: (context, state) =>
            ProjectDetailScreen(projectId: idOf(state)),
      ),
      GoRoute(
        path: '/projects/:id/edit',
        builder: (context, state) => ProjectFormScreen(projectId: idOf(state)),
      ),

      /* ---------------------------------------------------------- sites */
      GoRoute(
        path: '/sites',
        builder: (context, state) => const SitesListScreen(),
      ),
      GoRoute(
        path: '/sites/new',
        builder: (context, state) => const SiteFormScreen(),
      ),
      GoRoute(
        path: '/sites/:id',
        builder: (context, state) => SiteDetailScreen(siteId: idOf(state)),
      ),
      GoRoute(
        path: '/sites/:id/edit',
        builder: (context, state) => SiteFormScreen(siteId: idOf(state)),
      ),

      /* ---------------------------------------------------------- leave */

      // Phase 6. The same three rules as above, plus one: `/leave-balances`
      // is a *different first segment* from `/leave/:id`, so the two cannot
      // collide however the table is ordered — but `/leave/new` genuinely
      // can, and is therefore registered first.
      GoRoute(
        path: '/leave',
        builder: (context, state) => const LeaveListScreen(),
      ),
      GoRoute(
        path: '/leave/new',
        builder: (context, state) => const LeaveFormScreen(),
      ),
      GoRoute(
        path: '/leave-balances',
        builder: (context, state) => const LeaveBalanceScreen(),
      ),
      GoRoute(
        path: '/leave/:id',
        builder: (context, state) => LeaveDetailScreen(leaveId: idOf(state)),
      ),
      GoRoute(
        path: '/leave/:id/edit',
        builder: (context, state) => LeaveFormScreen(leaveId: idOf(state)),
      ),

      /* ------------------------------------------------------- holidays */

      // No `/holidays/:id` detail route: a holiday has no read-only screen
      // worth a navigation, and the list *is* the calendar. Only somebody
      // holding `holidays.manage` is offered a way in — see
      // HolidaysListScreen for why the chevron disappears with the button.
      GoRoute(
        path: '/holidays',
        builder: (context, state) => const HolidaysListScreen(),
      ),
      GoRoute(
        path: '/holidays/new',
        builder: (context, state) => const HolidayFormScreen(),
      ),
      GoRoute(
        path: '/holidays/:id/edit',
        builder: (context, state) => HolidayFormScreen(holidayId: idOf(state)),
      ),

      /* ------------------------------------------------------ timesheets */
      GoRoute(
        path: '/timesheets',
        builder: (context, state) => const TimesheetsListScreen(),
      ),
      GoRoute(
        path: '/timesheets/:id',
        builder: (context, state) =>
            TimesheetDetailScreen(timesheetId: idOf(state)),
      ),

      /* -------------------------------------------------------- overtime */
      GoRoute(
        path: '/overtime',
        builder: (context, state) => const OvertimeListScreen(),
      ),
      GoRoute(
        path: '/overtime/new',
        builder: (context, state) => const OvertimeFormScreen(),
      ),
      GoRoute(
        path: '/overtime/:id',
        builder: (context, state) =>
            OvertimeDetailScreen(overtimeId: idOf(state)),
      ),
      GoRoute(
        path: '/overtime/:id/edit',
        builder: (context, state) =>
            OvertimeFormScreen(overtimeId: idOf(state)),
      ),

      /* --------------------------------------------------- site reports */

      // Phase 7. Two lists, each with the same four shapes. `new` again
      // comes before `:id`, and — unlike the modules above — every read
      // screen here is reached from a permission-gated list rather than a
      // menu item, so a URL typed by hand lands on `NoPermission` instead
      // of a form that would be refused a second later.
      //
      // The activity report and the daily report are separate trees
      // rather than one with a mode: they have different authors, different
      // permissions, different child rows, and different lifecycle rules
      // (one per person per day versus one per site per day). Sharing a
      // screen would mean a `type` flag threaded through every field.
      GoRoute(
        path: '/site-reports',
        builder: (context, state) => const SiteActivityListScreen(),
      ),
      GoRoute(
        path: '/site-reports/new',
        builder: (context, state) => const SiteActivityFormScreen(),
      ),
      GoRoute(
        path: '/site-reports/:id',
        builder: (context, state) =>
            SiteActivityDetailScreen(reportId: idOf(state)),
      ),
      GoRoute(
        path: '/site-reports/:id/edit',
        builder: (context, state) =>
            SiteActivityFormScreen(reportId: idOf(state)),
      ),

      GoRoute(
        path: '/daily-reports',
        builder: (context, state) => const DailySiteReportListScreen(),
      ),
      GoRoute(
        path: '/daily-reports/new',
        builder: (context, state) => const DailySiteReportFormScreen(),
      ),
      GoRoute(
        path: '/daily-reports/:id',
        builder: (context, state) =>
            DailySiteReportDetailScreen(reportId: idOf(state)),
      ),
      GoRoute(
        path: '/daily-reports/:id/edit',
        builder: (context, state) =>
            DailySiteReportFormScreen(reportId: idOf(state)),
      ),

      /* ---------------------------------------------------- Phase 8: pay */

      // Four first segments, and the same ordering rules again (`new`
      // before `:id`, digits only). Three things are worth saying about
      // this group specifically:
      //
      //  - **`/salary-slips` is its own tree, not `/payroll` with a flag.**
      //    It sits behind `salary_slips.view` rather than `payroll.view`,
      //    so a role can be handed its own documents without being handed
      //    the ledger, and a screen built as a mode of the other one would
      //    have to keep switching which permission it was drawn from.
      //
      //  - **`/loans/new` before `/loans/:id`, and `/loans/:id/edit`
      //    after it** — the same brace-then-belt arrangement the employee
      //    routes use, because `new` would otherwise be parsed as a row id.
      //
      //  - **No route guards any of these on a permission**, exactly as
      //    above. Each screen asks `PermissionScope` what to draw and the
      //    API refuses whatever it should not have been asked for; a
      //    redirect hiding a URL would be a second authorisation system to
      //    keep in step with the first.
      GoRoute(
        path: '/payroll',
        builder: (context, state) => const PayrollListScreen(),
      ),
      GoRoute(
        path: '/payroll/:id',
        builder: (context, state) =>
            PayrollDetailScreen(payrollId: idOf(state)),
      ),
      GoRoute(
        path: '/salary-slips',
        builder: (context, state) => const SalarySlipsScreen(),
      ),

      GoRoute(
        path: '/loans',
        builder: (context, state) => const LoanListScreen(),
      ),
      GoRoute(
        path: '/loans/new',
        builder: (context, state) => const LoanFormScreen(),
      ),
      GoRoute(
        path: '/loans/:id',
        builder: (context, state) => LoanDetailScreen(loanId: idOf(state)),
      ),
      GoRoute(
        path: '/loans/:id/edit',
        builder: (context, state) => LoanFormScreen(loanId: idOf(state)),
      ),

      GoRoute(
        path: '/salary-certificates',
        builder: (context, state) => const SalaryCertificateListScreen(),
      ),
      GoRoute(
        path: '/salary-certificates/new',
        builder: (context, state) => const SalaryCertificateFormScreen(),
      ),
      GoRoute(
        path: '/salary-certificates/:id',
        builder: (context, state) =>
            SalaryCertificateDetailScreen(requestId: idOf(state)),
      ),
    ],
  );

  // Riverpod owns the router's lifetime. Both resources outlive exactly one
  // ProviderScope, and neither is safe to leak: a retained router keeps
  // listening to a notifier that no longer has a screen to refresh.
  ref.onDispose(() {
    authChanged.dispose();
    router.dispose();
  });

  return router;
});
