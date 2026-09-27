import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/overtime/domain/overtime_request.dart';
import 'package:mobile/features/overtime/presentation/overtime_list_screen.dart';

import '../../../support/phase6.dart';

OvertimeRequest claim({
  required int id,
  String date = '2026-09-27',
  String status = OvertimeRequest.statusPending,
  int requested = 120,
  int? approved,
  bool payrollEligible = false,
  String? site,
}) => OvertimeRequest.fromJson(<String, dynamic>{
  'id': id,
  'employee_id': 1,
  'employee': <String, dynamic>{'name': 'Anu Kmani'},
  'overtime_date': date,
  if (site != null) 'site': <String, dynamic>{'name': site},
  'requested_minutes': requested,
  'requested_hours': requested / 60,
  'approved_minutes': approved,
  'approved_hours': approved == null ? null : approved / 60,
  'reason': 'Handover to the night crew.',
  'status': status,
  'payroll_eligible': payrollEligible,
});

void main() {
  late ScriptedOvertime script;

  setUp(() {
    script = ScriptedOvertime(
      idOf: (item) => item.id,
      items: [
        claim(id: 1, site: 'Block A'),
        claim(id: 2, status: OvertimeRequest.statusDraft, requested: 45),
        claim(
          id: 3,
          date: '2026-09-20',
          status: OvertimeRequest.statusApproved,
          approved: 90,
          payrollEligible: true,
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['overtime.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase6(
        permissions: permissions,
        overtime: script,
        child: const MaterialApp(home: OvertimeListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without overtime.view is never asked for claims', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('claim-overtime')), findsNothing);
  });

  testWidgets('rows show the date, what was asked, and whose it is', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    expect(find.text('2026-09-27 · 120 min · 2.00 h'), findsOneWidget);
    expect(find.text('Anu Kmani · Block A'), findsOneWidget);
    expect(find.text('Awaiting approval'), findsOneWidget);
    expect(find.text('Draft'), findsOneWidget);
  });

  testWidgets('the payroll flag is its own chip, not a status', (tester) async {
    await pumpList(tester);

    // Approved and eligible are two separate facts — the second is the one a
    // payroll run reads, and it arrives from the server rather than being
    // derived here from the first.
    expect(find.text('Approved'), findsOneWidget);
    expect(find.text('Payroll'), findsOneWidget);
  });

  testWidgets('the claim door appears only for overtime.create', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['overtime.view']);

    expect(find.byKey(const ValueKey('claim-overtime')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['overtime.view', 'overtime.create'],
    );

    expect(find.byKey(const ValueKey('claim-overtime')), findsOneWidget);
  });

  testWidgets('the payroll filter is a query parameter, and switching it '
      'off removes the key', (tester) async {
    await pumpList(tester);

    expect(script.lastQuery, isNot(contains('payroll_eligible')));

    await tester.tap(find.byKey(const ValueKey('overtime-payroll-filter')));
    await tester.pumpAndSettle();

    expect(script.lastQuery!['payroll_eligible'], 'true');

    await tester.tap(find.byKey(const ValueKey('overtime-payroll-filter')));
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'payroll_eligible', null);
  });

  testWidgets('the status filter is a query parameter, and clearing it '
      'removes the key', (tester) async {
    await pumpList(tester);

    await tester.tap(find.byKey(const ValueKey('overtime-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Approved').last);
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'approved');

    await tester.tap(find.byKey(const ValueKey('overtime-status-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All statuses').last);
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'status', null);
  });
}
