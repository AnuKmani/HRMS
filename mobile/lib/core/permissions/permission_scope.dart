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

  /* ---------------------------------------------------------- payroll */

  /// The payroll module's own coarse gate.
  ///
  /// Holding it does **not** mean "everybody's payroll": `Visibility` narrows
  /// the rows to your own unless you also hold `payroll.manage` or
  /// `employees.salary.view`, so an Employee and a Payroll Admin can both
  /// hold this and see two completely different lists. The seeders grant it
  /// to the roles the spec names and to nobody else — Project Manager and
  /// Site Supervisor deliberately have no `payroll.*` at all.
  bool get canViewPayroll => can('payroll.view');

  /// Correcting the inputs, reviewing and finalising. Never the lock.
  bool get canManagePayroll => can('payroll.manage');

  /// Running a month, or re-running one row. Separate from `manage` because
  /// "may fix a figure" and "may restate a whole period" are different
  /// acts with different blast radii.
  bool get canProcessPayroll => can('payroll.process');

  /// The one irreversible button in the module — Payroll Admin and Super
  /// Admin only, and never implied by either grant above.
  bool get canLockPayroll => can('payroll.lock');

  /// Company totals with no rows behind them. Deliberately **not** a branch
  /// of [canViewPayroll]: a role that may read the totals and a role that may
  /// read the list are two roles, and merging them would hand Management the
  /// building's salaries along with the sum.
  bool get canViewPayrollSummary => can('payroll.summary.view');

  bool get canRunPayroll => canProcessPayroll;

  /* ------------------------------------------------------ salary slips */

  /// Reading a payslip. Every role that has any payroll visibility holds
  /// this, plus `salary_slips.manage` where an analyst needs to pull
  /// somebody else's document rather than only their own.
  bool get canViewSalarySlips => can('salary_slips.view');

  bool get canManageSalarySlips => can('salary_slips.manage');

  /* ----------------------------------------------- salary certificates */

  /// Enough to *ask* for a certificate about your own salary and to read the
  /// decision on it. Approving is `.manage`, and asking for somebody else's
  /// is `.manage` too — see `StoreSalaryCertificateRequest`.
  bool get canViewSalaryCertificates => can('salary_certificates.view');

  bool get canManageSalaryCertificates => can('salary_certificates.manage');

  /* ------------------------------------------------------------- loans */

  bool get canViewLoans => can('loans.view');

  /// Asking for one **for yourself**. A loan on somebody else's behalf needs
  /// [canManageLoans], and the server says so on the field.
  bool get canCreateLoans => can('loans.create');

  /// Answering somebody else's request. Never implied by
  /// [canCreateLoans] — nobody signs off on their own debt, and the seeders
  /// give the two grants to different roles on purpose.
  bool get canApproveLoans => can('loans.approve');

  bool get canManageLoans => can('loans.manage');

  /* --------------------------------------------------------- expenses */

  /// Reading expense claims. The list, the detail screen, the summary and
  /// the receipts all sit behind this one coarse door — *whose* claims are
  /// then narrowed by `Visibility::expensesFor()`, which is the server's
  /// answer and not this one.
  bool get canViewExpenses => can('expenses.view');

  /// Filing a claim of your own, and submitting or cancelling one.
  bool get canCreateExpenses => can('expenses.create');

  /// Correcting a draft. Separate from [canCreateExpenses] because the seed
  /// gives an Employee both but gives Payroll Admin only this — the back
  /// office can correct what it finds, not open a new line of spend.
  bool get canUpdateExpenses => can('expenses.update');

  /// Answering somebody else's claim. Never implied by
  /// [canCreateExpenses]: nobody signs off on their own expense any more
  /// than on their own loan, and the server refuses it in the same breath.
  bool get canApproveExpenses => can('expenses.approve');

  /// The back office — and the permission EXP-STD's "Finance / HR" link
  /// resolves to. Deliberately absent from [canCreateExpenses].
  bool get canManageExpenses => can('expenses.manage');

  /// Reading the *documents* attached to claims that are not yours.
  ///
  /// Own receipts need only [canViewExpenses]: evidence you filed yourself
  /// is not somebody else's secret. This is what a supervisor, finance or HR
  /// needs on top, and the seeders withhold it from Site Engineer on purpose
  /// so a role can read the numbers on a claim without being handed every
  /// invoice behind it.
  bool get canViewExpenseReceipts => can('expenses.receipts.view');

  bool get canWriteExpenses => canCreateExpenses || canUpdateExpenses;

  /* ------------------------------------------------------- documents */

  /// Reading employment documents. The list, the detail screen and the
  /// file itself all sit behind this one door — *whose* documents are then
  /// narrowed by `Visibility::employeeDocumentsFor()`, which is the server's
  /// answer and not this one: a holder without `documents.manage` sees their
  /// own file and nobody else's.
  bool get canViewDocuments => can('documents.view');

  /// Filing a document. For **yourself** with this alone; for a colleague
  /// the server additionally wants [canManageDocuments] — see
  /// `Visibility::mayFileDocumentsFor()`, which is why a supervisor who
  /// manages people is not by that fact handed their passports.
  bool get canCreateDocuments => can('documents.create');

  /// Correcting the details of a row. Never implied by
  /// [canCreateDocuments]: the seeders give an Employee both and give Payroll
  /// Admin neither, and merging them would let a role that may read open a
  /// field it was never meant to write.
  bool get canUpdateDocuments => can('documents.update');

  /// Signing a document off — or refusing it, which needs the same grant.
  /// Never implied by [canCreateDocuments]: nobody verifies their own
  /// passport, and the server refuses that in the same breath.
  bool get canVerifyDocuments => can('documents.verify');

  /// Archiving a row. A separate act from verifying, and deliberately
  /// terminal: nothing in this app un-archives one.
  bool get canDeleteDocuments => can('documents.delete');

  /// The cross-employee "what is about to lapse" report. Deliberately not
  /// implied by [canViewDocuments]: the question is about *everybody*, and
  /// the seeders withhold it from the roles that should only ever see their
  /// own file.
  bool get canViewDocumentExpiry => can('documents.expiry.view');

  /// The back office for other people's files — the row-level half of every
  /// question above. Holding [canViewDocuments] without this is a person
  /// reading their own employment file and nothing else.
  bool get canManageDocuments => can('documents.manage');

  bool get canWriteDocuments => canCreateDocuments || canUpdateDocuments;

  /* ------------------------------------------------------ onboarding */

  /// Reading the directory of where starters stand.
  bool get canViewOnboarding => can('onboarding.view');

  /// Moving a record's stage and completing it. Never implied by
  /// [canViewOnboarding], and never available for your own row: an employee
  /// signing off their own requirements is the same self-verification
  /// `documents.verify` refuses.
  bool get canManageOnboarding => can('onboarding.manage');

  /* --------------------------------------------------------- training */

  /// Reading the catalogue and your own course history. The row-level half
  /// — *whose* history — is `Visibility::employeeTrainingsFor()`'s answer
  /// and not this one: a holder without [canManageTraining] sees their own
  /// courses and nobody else's.
  bool get canViewTraining => can('training.view');

  /// The back office for other people: enrolling, editing and retiring.
  /// Without it [canViewTraining] is a person reading their own course
  /// history, which is exactly what the seeders intend for an Employee and a
  /// Project Manager.
  bool get canManageTraining => can('training.manage');

  /// Adding a course to the catalogue. Never implied by
  /// [canManageTraining]: `training.manage` is the door onto the *only*
  /// action it guards (retiring), and a role that may place people on a
  /// course is not by that fact allowed to invent one.
  bool get canCreatePrograms => can('training.create');

  bool get canUpdatePrograms => can('training.update');

  /// Correcting an enrolment — including cancelling it. The same grant
  /// `training.update` that [canUpdatePrograms] uses, because both are "fix
  /// a training record": one permission, two names for the two doors it
  /// opens, rather than one gate asked to guess which of the two a caller
  /// meant.
  bool get canEditEnrolments => can('training.update');

  /// Putting somebody on a course. Separate from [canManageTraining]
  /// because it is the act with a person attached to it, and the seeders
  /// grant it only to HR.
  bool get canAssignTraining => can('training.assign');

  /// Recording a pass — and, with a program that promises a card, the card
  /// itself. Never implied by [canAssignTraining]: whoever booked the seat
  /// is not automatically whoever signs off that it was passed.
  bool get canCompleteTraining => can('training.complete');

  /// Opening the *card* rather than the row it belongs to. Reading a list
  /// of courses somebody sat is a different act from being handed the
  /// document that came out of one — see
  /// `EmployeeTrainingPolicy::viewCertificate()`.
  bool get canViewTrainingCertificates => can('training.certificates.view');

  /// The cross-employee "whose card is about to lapse" report. Deliberately
  /// not implied by [canViewTraining]: the question is about everybody, and
  /// the seeders withhold it from the roles that should only ever read their
  /// own file.
  bool get canViewTrainingExpiry => can('training.expiry.view');

  /* ------------------------------------------------------------ assets */

  /// Reading the register. Without [canManageAssets] `Visibility` narrows it
  /// to the assets actually handed to you, so a Site Engineer holding this
  /// and a HR Executive holding both read two completely different pages of
  /// the same URL.
  bool get canViewAssets => can('assets.view');

  bool get canCreateAssets => can('assets.create');

  /// Correcting the master record — never condition and never status, which
  /// have their own doors.
  bool get canUpdateAssets => can('assets.update');

  /// The row-scope gate and the status control. Deliberately absent from
  /// [canViewAssets]: without it the register is "what has been handed to
  /// me", and with it the register is everything.
  bool get canManageAssets => can('assets.manage');

  /// Handing one out. Never implied by [canManageAssets] or
  /// [canUpdateAssets] — a person who may write the register down is not by
  /// that fact allowed to put company property in somebody's hands.
  bool get canAssignAssets => can('assets.assign');

  /// Taking one back. Split from [canAssignAssets] so a deployment can
  /// grant one without the other; the seeders give HR both.
  bool get canReturnAssets => can('assets.return');

  /// The cross-employee "who has held what" log. On top of [canViewAssets]
  /// rather than implied by it: every hand-over the company ever wrote is a
  /// different question from "show me the register".
  bool get canViewAssetHistory => can('assets.history.view');
}

/// Recomputed whenever the session changes, so a permission revoked by a
/// server-side role change reaches the UI on the next rebuild rather than
/// being cached from sign-in.
final permissionScopeProvider = Provider<PermissionScope>((ref) {
  final user = ref.watch(authControllerProvider.select((state) => state.user));

  return PermissionScope(user);
});
