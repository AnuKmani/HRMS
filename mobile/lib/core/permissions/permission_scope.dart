import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/auth_models.dart';
import '../../features/auth/auth_controller.dart';

/// One place the app asks "may the signed-in user do this?".
///
/// **This is convenience, not authorization.** Every one of these answers is
/// derived from the permission list the server attached to the session, and
/// the server independently enforces the same rules behind `permission:`
/// middleware and policies on every route (see docs/SECURITY.md). Hiding a
/// button stops an honest user from tapping something that would only come
/// back as a 403; it is not, and is not meant to be, a boundary.
///
/// The class exists rather than scattering `user.can('employees.view')` across
/// widgets for two reasons: the strings are written once, so a typo in a
/// screen cannot silently render as "no permission"; and callers can ask for a
/// *group* ("anything on this module") without inventing their own
/// `||` chain each time.
class PermissionScope {
  const PermissionScope(this._user);

  /// Null while signed out — and every answer below fails closed to `false`.
  final AuthUser? _user;

  bool can(String permission) => _user?.can(permission) ?? false;

  bool canAny(Iterable<String> permissions) {
    for (final permission in permissions) {
      if (can(permission)) return true;
    }
    return false;
  }

  bool canAll(Iterable<String> permissions) {
    for (final permission in permissions) {
      if (!can(permission)) return false;
    }
    return true;
  }

  /* ------------------------------------------------------- employees */

  bool get canViewEmployees => can('employees.view');

  bool get canCreateEmployees => can('employees.create');

  bool get canUpdateEmployees => can('employees.update');

  bool get canDeleteEmployees => can('employees.delete');

  /// The third gate. Holding `employees.view` does **not** imply it: payroll
  /// figures are shown only to roles the seeders granted `employees.salary.view`
  /// to (Super Admin, HR Admin, Payroll Admin, Finance).
  bool get canViewSalary => can('employees.salary.view');

  bool get canEditEmployees => canCreateEmployees || canUpdateEmployees;

  /* --------------------------------------------------- departments */

  bool get canViewDepartments => can('departments.view');

  bool get canManageDepartments => can('departments.manage');

  /* --------------------------------------------------- designations */

  bool get canViewDesignations => can('designations.view');

  bool get canManageDesignations => can('designations.manage');

  /* ------------------------------------------------------- projects */

  bool get canViewProjects => can('projects.view');

  bool get canManageProjects => can('projects.manage');

  /* ---------------------------------------------------------- sites */

  bool get canViewSites => can('sites.view');

  bool get canManageSites => can('sites.manage');

  /* ------------------------------------------------------------- leave */

  bool get canViewLeave => can('leave.view');

  bool get canCreateLeave => can('leave.create');

  bool get canApproveLeave => can('leave.approve');

  bool get canManageLeave => can('leave.manage');

  /// The balance screen. Deliberately a separate answer from `canViewLeave`:
  /// the seeders grant `leave.balance.view` to every role that has leave at
  /// all but `leave.balance.manage` to HR alone, and merging the two would
  /// turn "correct a pot by hand" into a button every employee could press.
  bool get canViewLeaveBalances => can('leave.balance.view');

  bool get canManageLeaveBalances => can('leave.balance.manage');

  /// There is no `holidays.view` — a day the company declared off is not a
  /// privilege within it, so `GET /holidays` is open to every signed-in
  /// account and only *writing* the calendar is a permission.
  bool get canManageHolidays => can('holidays.manage');

  /// Whether this session is allowed to *try* to file a medical certificate.
  /// "May this one have one?" is the request's own question and is answered
  /// from [LeaveRequest.certificateRequired] on the detail screen.
  bool get canFileCertificates => can('leave.create') || can('leave.manage');

  /* -------------------------------------------------------- timesheets */

  bool get canViewTimesheets => can('timesheets.view');

  /// Regenerating a period from attendance — not "editing" one, which does
  /// not exist: a timesheet is a snapshot and has no write endpoint at all.
  bool get canGenerateTimesheets => can('timesheets.manage');

  /* ---------------------------------------------------------- overtime */

  bool get canViewOvertime => can('overtime.view');

  bool get canCreateOvertime => can('overtime.create');

  bool get canApproveOvertime => can('overtime.approve');

  bool get canManageOvertime => can('overtime.manage');

  /* ---------------------------------------------------- assignments */

  bool get canViewAssignments => can('assignments.view');

  bool get canManageAssignments => can('assignments.manage');

  /* ---------------------------------------------------- site reports */

  /// A person's own account of a site-day. Every role that has the module at
  /// all holds `.create`, because filing your own note about your own site is
  /// not an act of authority — but `.view` alone does not mean *every* note,
  /// and the row-level half of that question is the server's.
  bool get canViewSiteActivityReports => can('site_activity_reports.view');

  bool get canCreateSiteActivityReports => can('site_activity_reports.create');

  bool get canUpdateSiteActivityReports => can('site_activity_reports.update');

  /// The official site-day document. Deliberately **not** implied by the
  /// activity permissions: an Employee holds all three activity grants and
  /// none of these, because the whole company cannot be preparing the one
  /// record a review reads.
  bool get canViewDailySiteReports => can('daily_site_reports.view');

  bool get canCreateDailySiteReports => can('daily_site_reports.create');

  bool get canUpdateDailySiteReports => can('daily_site_reports.update');

  bool get canManageDailySiteReports => can('daily_site_reports.manage');

  /// Exporting the document is a separate act from reading the numbers on a
  /// screen, and the seeders grant the two together — so a deployment that
  /// wants one without the other can revoke `.pdf` without taking `.view`.
  bool get canExportDailySiteReports => can('daily_site_reports.pdf');

  bool get canWriteSiteReports =>
      canCreateSiteActivityReports || canCreateDailySiteReports;
}

/// Recomputed whenever the session changes, so a permission revoked by a
/// server-side role change reaches the UI on the next rebuild rather than
/// being cached from sign-in.
final permissionScopeProvider = Provider<PermissionScope>((ref) {
  final user = ref.watch(authControllerProvider.select((state) => state.user));

  return PermissionScope(user);
});
