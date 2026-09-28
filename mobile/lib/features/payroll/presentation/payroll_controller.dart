import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_payroll_repository.dart';
import '../domain/payroll.dart';

/// The month this list opens on.
///
/// Payroll is a *period* view rather than an infinite feed: nobody has ever
/// wanted "every payroll row ever", they want September. Anchoring the query
/// to the current year and month means the first frame is already the answer
/// to the question a payroll clerk opens the screen to ask, and the "all
/// periods" option remains one chip away for the audit-shaped question.
({int year, int? month}) currentPayrollPeriod() {
  final now = DateTime.now();

  return (year: now.year, month: now.month);
}

/// The query that period becomes — one shape for the controller's
/// `initialQuery` and for the summary's period when there is no list to
/// read a period from.
///
/// A function rather than a constant so the year is read when it is needed
/// rather than the first time this file is loaded, and so a test that
/// advances past midnight does not find the previous month's rows.
Map<String, Object?> currentPayrollQuery() {
  final (year: year, month: month) = currentPayrollPeriod();

  return <String, Object?>{'year': year, 'month': month};
}

/// The rows behind `GET /payroll`.
///
/// Year and month are query parameters rather than a Dart-side filter over
/// the page — `PayrollController::index` totals them into `meta.total`, and
/// narrowing the page here would put the server's count out of step with
/// what is drawn, which is exactly the kind of small lie that makes a
/// pagination footer worse than none.
final payrollListProvider =
    NotifierProvider<PayrollListController, ListState<Payroll>>(
      PayrollListController.new,
    );

class PayrollListController extends PagedListController<Payroll> {
  @override
  Map<String, Object?> get initialQuery => currentPayrollQuery();

  @override
  Future<PageResult<Payroll>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(payrollRepositoryProvider).list(page: page, query: query);
}

/// The rows behind `GET /salary-slips`.
///
/// A separate controller rather than a mode flag on [payrollListProvider]:
/// the two endpoints sit behind two different permissions, and one list
/// provider serving both would mean switching accounts could leave a screen
/// showing rows it fetched under the other grant.
final salarySlipListProvider =
    NotifierProvider<SalarySlipListController, ListState<Payroll>>(
      SalarySlipListController.new,
    );

class SalarySlipListController extends PagedListController<Payroll> {
  @override
  Future<PageResult<Payroll>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(payrollRepositoryProvider).slips(page: page, query: query);
}
