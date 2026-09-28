import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/loans/domain/loan.dart';
import 'package:mobile/features/loans/presentation/loan_list_screen.dart';

import '../../../support/phase8.dart';

void main() {
  late ScriptedLoans script;

  setUp(() {
    script = ScriptedLoans(
      idOf: (item) => item.id,
      items: [
        loanRow(id: 1, status: Loan.statusPending, reference: 'ADV-1'),
        loanRow(
          id: 2,
          name: 'Meera Nair',
          status: Loan.statusActive,
          outstanding: '6000.00',
          repaid: '6000.00',
        ),
        loanRow(
          id: 3,
          name: 'Ravi Kumar',
          status: Loan.statusCompleted,
          outstanding: '0.00',
          repaid: '12000.00',
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['loans.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        loans: script,
        child: const MaterialApp(home: LoanListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without loans.view is never asked for rows', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('new-loan')), findsNothing);
  });

  testWidgets('a row says what was borrowed, from whom, and what is left', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

    expect(find.text('Loan · ADV-1'), findsOneWidget);
    expect(
      find.text('Anu Kmani · from 2026-10-05 · 12 × 1000.00'),
      findsOneWidget,
    );
    expect(find.text('Awaiting approval'), findsOneWidget);

    // The leading figure is the *outstanding* balance — the number a
    // borrower actually asks about — not the principal they were given.
    expect(find.text('INR 12,000.00'), findsOneWidget);
    expect(find.text('INR 6,000.00'), findsOneWidget);

    // Scoped to the row: `Completed` is also the label of the filter chip
    // above the list, and a bare `find.text` would be counting both.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('loan-row-3')),
        matching: find.text('Completed'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the ask-for-a-loan door belongs to loans.create', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['loans.view']);
    expect(find.byKey(const ValueKey('new-loan')), findsNothing);

    await pumpList(tester, permissions: const ['loans.view', 'loans.create']);
    expect(find.byKey(const ValueKey('new-loan')), findsOneWidget);
  });

  testWidgets('the status filter is a query parameter, and clearing it '
      'removes the key', (tester) async {
    await pumpList(tester);

    expectQuery(script.lastQuery, 'status', null);

    await tester.tap(find.byKey(const ValueKey('loan-filter-active')));
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'active');

    await tester.tap(find.byKey(const ValueKey('loan-filter-all')));
    await tester.pumpAndSettle();

    expectQuery(script.lastQuery, 'status', null);
  });
}
