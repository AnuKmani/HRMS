import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/payroll/domain/payroll.dart';
import 'package:mobile/features/payroll/presentation/payroll_controller.dart';
import 'package:mobile/features/payroll/presentation/payroll_list_screen.dart';

import '../../../support/phase8.dart';

void main() {
  late ScriptedPayroll script;

  setUp(() {
    script = ScriptedPayroll(
      idOf: (item) => item.id,
      items: [
        payrollRow(id: 1),
        payrollRow(
          id: 2,
          name: 'Meera Nair',
          status: Payroll.statusLocked,
          net: '31000.00',
          canRecalculate: false,
        ),
        payrollRow(
          id: 3,
          name: 'Ravi Kumar',
          status: Payroll.statusDraft,
          net: '0.00',
          canRecalculate: false,
          blockedReason: 'No salary on record.',
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['payroll.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        payroll: script,
        child: const MaterialApp(home: PayrollListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session with no payroll grant is never asked for rows', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
  });

  testWidgets('the list opens on the current month and says which one', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

    final (year: year, month: month) = currentPayrollPeriod();
    expect(script.lastQuery!['year'], year);
    expect(script.lastQuery!['month'], month);

    expect(find.byKey(const ValueKey('payroll-period-label')), findsOneWidget);
    expect(find.text('Anu Kmani'), findsOneWidget);
    expect(find.text('September 2026 · EMP01'), findsOneWidget);
  });

  testWidgets('a net salary is drawn through the one money formatter', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.text('INR 28,500.00'), findsOneWidget);
    expect(find.text('INR 31,000.00'), findsOneWidget);
  });

  testWidgets('a row with no salary shows the reason, not a zero', (
    tester,
  ) async {
    await pumpList(tester);

    // `0.00` and "no salary on record" are different facts and only the
    // server knows which one it meant. The reason rides in the row's own
    // subtitle — joined to the period and the code — so it is looked for as
    // part of a line rather than as a whole one.
    expect(
      find.text('September 2026 · EMP03 · No salary on record.'),
      findsOneWidget,
    );
  });

  testWidgets('switching to all periods drops year and month from the query', (
    tester,
  ) async {
    await pumpList(tester);

    expect(script.lastQuery, contains('year'));

    await tester.tap(find.byKey(const ValueKey('payroll-year-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All').last);
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'year', null);
    expectQuery(script.lastQuery, 'month', null);
    expect(find.text('all periods'), findsOneWidget);
  });

  testWidgets('the run button belongs to payroll.process, and nobody else', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['payroll.view']);
    expect(find.byKey(const ValueKey('run-payroll')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['payroll.view', 'payroll.process'],
    );
    expect(find.byKey(const ValueKey('run-payroll')), findsOneWidget);
  });

  testWidgets('a run reports counts, never figures', (tester) async {
    // The period a run acts on is whatever the screen has open — today's —
    // so the expectations are read from the same helper the controller uses
    // rather than written down, which is what made this test start failing
    // when September ended.
    final period = currentPayrollQuery();

    script.processResult = PayrollRunReport(
      year: period['year']! as int,
      month: period['month']! as int,
      calculated: 41,
      updated: 30,
      created: 11,
      skipped: 0,
      draft: 2,
    );

    await pumpList(
      tester,
      permissions: const ['payroll.view', 'payroll.process'],
    );

    await tester.tap(find.byKey(const ValueKey('run-payroll')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('run-payroll-confirm')));
    await advance(tester);

    // SnackBars queue: the "Running payroll…" notice has to serve its four
    // seconds before the report is shown, so the clock is moved past it —
    // `pumpAndSettle` would not, having nothing left to animate.
    for (var i = 0; i < 55; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }

    expect(script.processCalls, 1);
    expect(script.lastProcessYear, period['year']);
    expect(script.lastProcessMonth, period['month']);
    expect(find.byKey(const ValueKey('payroll-run-report')), findsOneWidget);
    expect(
      find.text('41 calculated · 11 new · 0 skipped · 2 without a salary'),
      findsOneWidget,
    );
  });

  testWidgets('the summary is drawn for a role that may not see rows', (
    tester,
  ) async {
    script.summaryResult = const PayrollSummary(
      year: 2026,
      month: 9,
      employeeCount: 43,
      grossPayroll: '1290000.00',
      totalDeductions: '64500.00',
      netPayroll: '1225500.00',
      currency: 'INR',
    );

    await pumpList(tester, permissions: const ['payroll.summary.view']);
    await advance(tester);

    expect(script.listCalls, 0);
    expect(script.summaryCalls, 1);
    expect(find.byKey(const ValueKey('no-permission')), findsNothing);
    expect(find.byKey(const ValueKey('payroll-summary-head')), findsOneWidget);
    expect(find.text('INR 1,225,500.00'), findsOneWidget);

    // The head count is a number of people, not a name — the whole reason
    // `payroll.summary.view` is a separate grant.
    expect(find.textContaining('43 employees'), findsOneWidget);
    expect(find.text('Anu Kmani'), findsNothing);
  });

  testWidgets('the slips door belongs to salary_slips.view', (tester) async {
    await pumpList(tester, permissions: const ['payroll.view']);
    expect(find.byKey(const ValueKey('open-salary-slips')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['payroll.view', 'salary_slips.view'],
    );
    expect(find.byKey(const ValueKey('open-salary-slips')), findsOneWidget);
  });
}
