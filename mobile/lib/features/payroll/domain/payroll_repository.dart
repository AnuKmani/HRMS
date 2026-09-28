import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import 'payroll.dart';

/// How the payroll feature reaches the server.
///
/// Two things about this contract that are worth stating, because they are
/// the whole shape of Phase 8:
///
///  - **it is read-mostly.** Five of the eight verbs return a figure; the
///    three that change one are `process`, `recalculate` and the three
///    transitions, and every one of them is a *server* decision about a row
///    the caller may already have stopped being allowed to touch. Nothing
///    here posts a calculated amount — the calculation lives in
///    `PayrollCalculationService` and never leaves the backend.
///
///  - **the transitions are named, not collected.** `review()`, `finalize()`
///    and `lock()` are three methods rather than `act('review')`: a typo in a
///    string parameter is a 404 against an endpoint that never existed, and
///    on a screen whose last button is irreversible that is not a bug you
///    want to discover by pressing it.
abstract class PayrollRepository {
  Future<PageResult<Payroll>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  /// The rows behind `GET /salary-slips` — the same projection, behind the
  /// other grant. Separate on purpose: a session may hold
  /// `salary_slips.view` without `payroll.view`, and folding the two into one
  /// endpoint would make one of the two permissions meaningless.
  Future<PageResult<Payroll>> slips({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Payroll> find(int id);

  /// Company totals. Returns aggregates and nothing else — that is what
  /// `payroll.summary.view` is a grant *for*.
  Future<PayrollSummary> summary({
    required int year,
    int? month,
    int? departmentId,
  });

  Future<PayrollRunReport> process({
    required int year,
    required int month,
    List<int>? employeeIds,
  });

  Future<Payroll> recalculate(int id);

  Future<Payroll> review(int id);

  Future<Payroll> finalize(int id);

  Future<Payroll> lock(int id);

  /// The payslip, rendered on demand and never fetched from a stored file.
  Future<Uint8List> slipPdf(int id);
}
