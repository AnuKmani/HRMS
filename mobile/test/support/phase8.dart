import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/core/presentation/pdf_opener.dart';
import 'package:mobile/features/loans/data/api_loan_repository.dart';
import 'package:mobile/features/loans/domain/loan.dart';
import 'package:mobile/features/loans/domain/loan_repository.dart';
import 'package:mobile/features/payroll/data/api_payroll_repository.dart';
import 'package:mobile/features/payroll/domain/payroll.dart';
import 'package:mobile/features/payroll/domain/payroll_repository.dart';
import 'package:mobile/features/salary_certificates/data/api_salary_certificate_repository.dart';
import 'package:mobile/features/salary_certificates/domain/salary_certificate.dart';
import 'package:mobile/features/salary_certificates/domain/salary_certificate_repository.dart';

import 'fakes.dart';
import 'phase4.dart';
import 'phase6.dart';

export 'phase4.dart' show advance, useTallScreen, forbidden403, notFound404;
export 'phase6.dart' show expectQuery, pageOf;

/// The payroll repository, scripted.
///
/// Two pools rather than one, because `list` and `slips` are two different
/// questions against two different permissions — a test that collapsed them
/// could never show that a session holding only `salary_slips.view` still
/// gets its documents.
///
/// The three ladder verbs record what they were called with and then return
/// a row whose status has already moved, because that is what the screen
/// cares about: pressing *Review* should replace a `calculated` row with a
/// `reviewed` one, and a double that returned the old row would let a bug in
/// the screen's refresh pass unnoticed.
class ScriptedPayroll extends Scripted<Payroll> implements PayrollRepository {
  ScriptedPayroll({
    super.items,
    required super.idOf,
    super.fallback,
    this.slipRows = const <Payroll>[],
  });

  /// Rows behind `slips()`. Separate from [items] so a test can give an
  /// employee a payslip list without also giving them the ledger.
  final List<Payroll> slipRows;

  PayrollSummary? summaryResult;
  PayrollRunReport? processResult;

  int slipCalls = 0;
  int summaryCalls = 0;
  int processCalls = 0;
  int slipPdfCalls = 0;

  int? lastSummaryYear;
  int? lastSummaryMonth;
  int? lastProcessYear;
  int? lastProcessMonth;

  /// Every transition, in the order the screen asked for them.
  final List<String> transitions = <String>[];

  Object? actionError;

  @override
  Future<PageResult<Payroll>> slips({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    slipCalls++;
    return pageOf(slipRows, page);
  }

  @override
  Future<PayrollSummary> summary({
    required int year,
    int? month,
    int? departmentId,
  }) async {
    summaryCalls++;
    lastSummaryYear = year;
    lastSummaryMonth = month;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return summaryResult ??
        const PayrollSummary(
          year: 0,
          employeeCount: 0,
          grossPayroll: '0.00',
          totalDeductions: '0.00',
          netPayroll: '0.00',
          currency: 'INR',
        );
  }

  @override
  Future<PayrollRunReport> process({
    required int year,
    required int month,
    List<int>? employeeIds,
  }) async {
    processCalls++;
    lastProcessYear = year;
    lastProcessMonth = month;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return processResult ??
        PayrollRunReport(
          year: year,
          month: month,
          calculated: 0,
          updated: 0,
          created: 0,
          skipped: 0,
          draft: 0,
        );
  }

  @override
  Future<Payroll> recalculate(int id) => _transition('recalculate', id);

  @override
  Future<Payroll> review(int id) => _transition('review', id);

  @override
  Future<Payroll> finalize(int id) => _transition('finalize', id);

  @override
  Future<Payroll> lock(int id) => _transition('lock', id);

  Future<Payroll> _transition(String name, int id) async {
    transitions.add(name);

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    final row = await find(id);

    // The server answers every transition with the row as it now stands;
    // answering with the row as it *was* would let a screen show a stale
    // status and believe it had moved on.
    return Payroll.fromJson(<String, dynamic>{
      'id': row.id,
      'employee_id': row.employeeId,
      'payroll_year': row.payrollYear,
      'payroll_month': row.payrollMonth,
      'period_label': row.periodLabel,
      'basic_salary': row.basicSalary,
      'total_allowances': row.totalAllowances,
      'overtime_amount': row.overtimeAmount,
      'overtime_minutes': row.overtimeMinutes,
      'bonus_amount': row.bonusAmount,
      'gross_salary': row.grossSalary,
      'lop_days': row.lopDays,
      'lop_divisor': row.lopDivisor,
      'lop_amount': row.lopAmount,
      'loan_deduction': row.loanDeduction,
      'advance_deduction': row.advanceDeduction,
      'other_deductions': row.otherDeductions,
      'total_deductions': row.totalDeductions,
      'net_salary': row.netSalary,
      'status': switch (name) {
        'review' => Payroll.statusReviewed,
        'finalize' => Payroll.statusProcessed,
        'lock' => Payroll.statusLocked,
        _ => Payroll.statusCalculated,
      },
      'can_recalculate': name != 'lock',
      'can_lock': name == 'finalize',
      'currency': row.currency,
    });
  }

  @override
  Future<Uint8List> slipPdf(int id) async {
    slipPdfCalls++;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return Uint8List.fromList(const <int>[37, 80, 68, 70]);
  }
}

/// The loans repository, scripted.
///
/// The four lifecycle verbs are recorded rather than modelled: a screen test
/// asks "did pressing Approve call `approve` with this id?", and a double
/// that actually moved rows through `draft → pending → approved` would be
/// re-testing `LoanService` in Dart.
class ScriptedLoans extends Scripted<Loan> implements LoanRepository {
  ScriptedLoans({super.items, required super.idOf, super.fallback});

  String? lastTransition;
  int? lastTransitionId;
  String? lastRemarks;

  /// The body `create` was handed, kept apart from [Scripted]'s own
  /// [Scripted.lastBody] because this form's body is what the test is about:
  /// "did it send `loan_type`, and never `status`?"
  Map<String, Object?>? lastCreated;

  Object? actionError;

  Future<Loan> _transition(String name, int id, {String? remarks}) async {
    lastTransition = name;
    lastTransitionId = id;
    lastRemarks = remarks;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return find(id);
  }

  @override
  Future<Loan> submit(int id) => _transition('submit', id);

  @override
  Future<Loan> approve(int id, {String? remarks}) =>
      _transition('approve', id, remarks: remarks);

  @override
  Future<Loan> reject(int id, {String? remarks}) =>
      _transition('reject', id, remarks: remarks);

  @override
  Future<Loan> cancel(int id) => _transition('cancel', id);

  @override
  Future<Loan> create(Map<String, Object?> body) async {
    lastCreated = body;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return super.create(body);
  }

  @override
  Future<Loan> update(int id, Map<String, Object?> body) async {
    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    // `lastId` and `lastBody` are [Scripted]'s own records; the edit screen's
    // test reads them rather than two more fields with the same meaning.
    return super.update(id, body);
  }
}

/// The salary certificate repository, scripted.
class ScriptedCertificates extends Scripted<SalaryCertificateRequest>
    implements SalaryCertificateRepository {
  ScriptedCertificates({super.items, required super.idOf, super.fallback});

  String? lastTransition;
  int? lastTransitionId;
  String? lastRemarks;
  Map<String, Object?>? lastCreated;
  int pdfCalls = 0;
  int? lastPdfId;

  Object? actionError;

  Future<SalaryCertificateRequest> _transition(
    String name,
    int id, {
    String? remarks,
  }) async {
    lastTransition = name;
    lastTransitionId = id;
    lastRemarks = remarks;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return find(id);
  }

  @override
  Future<SalaryCertificateRequest> approve(int id, {String? remarks}) =>
      _transition('approve', id, remarks: remarks);

  @override
  Future<SalaryCertificateRequest> reject(int id, {String? remarks}) =>
      _transition('reject', id, remarks: remarks);

  @override
  Future<SalaryCertificateRequest> cancel(int id) => _transition('cancel', id);

  @override
  Future<SalaryCertificateRequest> create(Map<String, Object?> body) async {
    lastCreated = body;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return super.create(body);
  }

  @override
  Future<Uint8List> pdf(int id) async {
    pdfCalls++;
    lastPdfId = id;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return Uint8List.fromList(const <int>[37, 80, 68, 70]);
  }
}

/// Records the filenames a screen handed to the PDF opener, without touching
/// a file system or asking the OS for a viewer.
class RecordingPdfOpener implements PdfOpener {
  final List<String> opened = <String>[];
  int calls = 0;

  Object? error;

  @override
  Future<void> openBytes(Uint8List bytes, String filename) async {
    calls++;
    opened.add(filename);

    final failure = error;
    if (failure != null) {
      error = null;
      throw failure;
    }
  }
}

/// Wraps a screen in the providers Phase 8 needs: a permission scope with
/// exactly the permissions under test, plus any scripted repositories.
///
/// The override list is written out inside the `ProviderScope` literal for
/// the same reason it is in `scopedPhase4`: `Override` is a riverpod type
/// that `flutter_riverpod` does not re-export, so naming it would mean
/// importing a package this project does not declare.
Widget scopedPhase8({
  required Widget child,
  List<String> permissions = const <String>[],
  List<String> roles = const <String>['Employee'],
  ScriptedPayroll? payroll,
  ScriptedLoans? loans,
  ScriptedCertificates? certificates,
  PdfOpener? pdfOpener,
}) => ProviderScope(
  overrides: [
    permissionScopeProvider.overrideWithValue(
      PermissionScope(buildUser(permissions: permissions, roles: roles)),
    ),
    if (payroll != null) payrollRepositoryProvider.overrideWithValue(payroll),
    if (loans != null) loanRepositoryProvider.overrideWithValue(loans),
    if (certificates != null)
      salaryCertificateRepositoryProvider.overrideWithValue(certificates),
    if (pdfOpener != null) pdfOpenerProvider.overrideWithValue(pdfOpener),
  ],
  child: child,
);

/// A router with enough of the app's real path table for a Phase 8 screen
/// to navigate the way it does in production.
GoRouter phase8Router(String initialLocation, List<GoRoute> routes) =>
    GoRouter(initialLocation: initialLocation, routes: routes);

/* ------------------------------------------------------------- fixtures */

/// One payslip line, as `PayrollItemResource` shapes it.
PayrollItem payrollItem({
  required int id,
  String type = PayrollItem.typeEarning,
  String code = 'BASIC',
  String description = 'Basic salary',
  String? quantity,
  String? rate,
  String amount = '30000.00',
}) => PayrollItem.fromJson(<String, dynamic>{
  'id': id,
  'type': type,
  'code': code,
  'description': description,
  'quantity': quantity,
  'rate': rate,
  'amount': amount,
});

/// A payroll row as `PayrollResource` sends one.
///
/// A builder rather than a constant because the ladder is the subject of
/// most Phase 8 screen tests, and the state it sits in is exactly the
/// variable under test.
Payroll payrollRow({
  required int id,
  String name = 'Anu Kmani',
  String status = Payroll.statusCalculated,
  String net = '28500.00',
  String period = 'September 2026',
  bool canRecalculate = true,
  bool canLock = false,
  String? blockedReason,
  String? reviewedAt,
  String? processedAt,
  String? lockedAt,
  String currency = 'INR',
  List<PayrollItem> items = const <PayrollItem>[],
}) => Payroll.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': id,
  'employee': <String, dynamic>{'full_name': name, 'employee_code': 'EMP0$id'},
  'payroll_year': 2026,
  'payroll_month': 9,
  'period_label': period,
  'basic_salary': '30000.00',
  'total_allowances': '0.00',
  'overtime_amount': '0.00',
  'overtime_minutes': 0,
  'bonus_amount': '0.00',
  'gross_salary': '30000.00',
  'lop_days': '0.00',
  'lop_divisor': '30.00',
  'lop_amount': '0.00',
  'loan_deduction': '1500.00',
  'advance_deduction': '0.00',
  'other_deductions': '0.00',
  'total_deductions': '1500.00',
  'net_salary': net,
  'status': status,
  'blocked_reason': blockedReason,
  'can_recalculate': canRecalculate,
  'can_lock': canLock,
  'currency': currency,
  'reviewed_at': reviewedAt,
  'processed_at': processedAt,
  'locked_at': lockedAt,
  'items': items
      .map(
        (item) => <String, dynamic>{
          'id': item.id,
          'type': item.type,
          'code': item.code,
          'description': item.description,
          'quantity': item.quantity,
          'rate': item.rate,
          'amount': item.amount,
        },
      )
      .toList(),
});

/// One payment of a repayment schedule.
///
/// The defaults describe a payment nothing has touched yet: 1,000.00
/// scheduled, 0.00 taken, 1,000.00 still to come. A payment the pay run
/// could only partly take says so with `deductedAmount` and a
/// `statusPartiallyDeducted`, which is what the floor rule produces.
LoanInstallment loanInstallment({
  required int sequence,
  String dueDate = '2026-10-31',
  String amount = '1000.00',
  String deductedAmount = '0.00',
  String? remainingAmount,
  String status = LoanInstallment.statusPending,
  int? payrollId,
  String? skippedReason,
}) => LoanInstallment.fromJson(<String, dynamic>{
  'sequence': sequence,
  'due_date': dueDate,
  'amount': amount,
  'deducted_amount': deductedAmount,
  'remaining_amount': remainingAmount ?? amount,
  'status': status,
  'payroll_id': payrollId,
  'deducted_at': payrollId == null ? null : '2026-09-30T12:00:00Z',
  'skipped_reason': skippedReason,
});

/// A loan or salary advance, as `LoanResource` shapes it.
Loan loanRow({
  required int id,
  String name = 'Anu Kmani',
  String status = Loan.statusDraft,
  String type = Loan.typeLoan,
  String principal = '12000.00',
  String outstanding = '12000.00',
  String repaid = '0.00',
  String each = '1000.00',
  int count = 12,
  String? reference,
  String? remarks,
  String? nextDue,
  List<LoanInstallment> installments = const <LoanInstallment>[],
}) => Loan.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': id,
  'employee': <String, dynamic>{'full_name': name, 'employee_code': 'EMP0$id'},
  'loan_type': type,
  'type_label': type == Loan.typeSalaryAdvance ? 'Salary advance' : 'Loan',
  'reference': reference,
  'currency': 'INR',
  'principal_amount': principal,
  'installment_amount': each,
  'number_of_installments': count,
  'outstanding_balance': outstanding,
  'repaid_amount': repaid,
  'start_date': '2026-10-05',
  'status': status,
  'next_installment': nextDue == null
      ? null
      : <String, dynamic>{
          'sequence': 1,
          'due_date': nextDue,
          'amount': each,
          'deducted_amount': '0.00',
          'remaining_amount': each,
          'status': LoanInstallment.statusPending,
        },
  'remarks': remarks,
  'installments': installments
      .map(
        (installment) => <String, dynamic>{
          'sequence': installment.sequence,
          'due_date': installment.dueDate,
          'amount': installment.amount,
          'deducted_amount': installment.deductedAmount,
          'remaining_amount': installment.remainingAmount,
          'status': installment.status,
          'payroll_id': installment.payrollId,
          'deducted_at': installment.deductedAt,
          'skipped_reason': installment.skippedReason,
        },
      )
      .toList(),
  'approved_at': status == Loan.statusApproved || status == Loan.statusActive
      ? '2026-09-28T10:00:00Z'
      : null,
  'created_at': '2026-09-25T08:00:00Z',
});

/// A salary certificate request, as `SalaryCertificateRequestResource` shapes
/// it.
SalaryCertificateRequest certificateRow({
  required int id,
  String name = 'Anu Kmani',
  String status = SalaryCertificateRequest.statusPending,
  String purpose = 'Housing loan with National Bank',

  /// Defaults to the row's own id, because a fixture that stamped every row
  /// `SAL-CERT-000007` would make four indistinguishable titles and a test
  /// could then pass against the wrong one.
  String? reference,
  String? remarks,
  String? approvedAt,
  String? generatedAt,
  bool? canIssue,
}) => SalaryCertificateRequest.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': id,
  'employee': <String, dynamic>{'full_name': name, 'employee_code': 'EMP0$id'},
  'reference': reference ?? 'SAL-CERT-${id.toString().padLeft(6, '0')}',
  'request_date': '2026-09-28',
  'purpose': purpose,
  'status': status,
  // `can_issue` is the server's own answer — grant plus state — so the
  // tests that need it refused give it explicitly rather than hoping the
  // fixture guessed the caller's permissions.
  'can_issue':
      canIssue ??
      (status == SalaryCertificateRequest.statusApproved ||
          status == SalaryCertificateRequest.statusGenerated),
  'approved_at': approvedAt,
  'generated_at': generatedAt,
  'remarks': remarks,
  'created_at': '2026-09-28T09:00:00Z',
});
