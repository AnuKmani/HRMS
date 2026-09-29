import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/expenses/domain/expense.dart';
import 'package:mobile/features/expenses/presentation/expense_list_screen.dart';

import '../../../support/phase9.dart';

void main() {
  late ScriptedExpenses script;

  setUp(() {
    script = ScriptedExpenses(
      idOf: (item) => item.id,
      items: [
        expenseRow(id: 1, status: Expense.statusDraft),
        expenseRow(
          id: 2,
          status: Expense.statusPending,
          amount: '45.00',
          category: 'Site Expense',
          siteId: 3,
          siteName: 'Block A',
          receiptCount: 1,
        ),
        expenseRow(
          id: 3,
          name: 'Meera Nair',
          status: Expense.statusApproved,
          amount: '1000.00',
          category: 'Food',
          projectId: 11,
          projectName: 'Riverfront Towers',
        ),
      ],
    );
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['expenses.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase9(
        permissions: permissions,
        expenses: script,
        child: const MaterialApp(home: ExpenseListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without expenses.view is never asked for rows', (
    tester,
  ) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    expect(find.byKey(const ValueKey('claim-expense')), findsNothing);
  });

  testWidgets('a row says what was spent, where, and where it stands in '
      'the chain', (tester) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

    // The figure is printed from the server's decimal string, in the row's
    // own currency — a list of claims is a column of numbers, and each one
    // is compared against a paper receipt rather than against its neighbours.
    expect(find.text('2026-09-28 · INR 250.00'), findsOneWidget);
    expect(find.text('Travel · Anu Kmani'), findsOneWidget);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expense-row-2')),
        matching: find.text('Site Expense · Anu Kmani · Block A'),
      ),
      findsOneWidget,
    );

    expect(find.text('2026-09-28 · INR 1,000.00'), findsOneWidget);

    // Scoped to the row: "Awaiting approval" is also an option in the status
    // filter above, and a bare `find.text` would be counting both.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('expense-row-2')),
        matching: find.text('Awaiting approval'),
      ),
      findsOneWidget,
    );

    // Only the claim that has evidence says so.
    expect(find.text('1 receipt'), findsOneWidget);
  });

  testWidgets('the ask-for-a-claim door belongs to expenses.create', (
    tester,
  ) async {
    await pumpList(tester, permissions: const ['expenses.view']);
    expect(find.byKey(const ValueKey('claim-expense')), findsNothing);

    await pumpList(
      tester,
      permissions: const ['expenses.view', 'expenses.create'],
    );
    expect(find.byKey(const ValueKey('claim-expense')), findsOneWidget);
  });

  testWidgets('the status filter is a query parameter, not a client-side '
      'slice', (tester) async {
    await pumpList(tester);

    expect(script.lastQuery!['status'], isNull);

    await tester.tap(
      find.byKey(const ValueKey('expense-status-filter')),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    // Scoped to the menu: the list behind it holds a pending claim whose own
    // chip reads the same words.
    // Scoped to the menu: the list behind it holds a pending claim whose own
    // chip reads the same words. `warnIfMissed` off because the menu item's
    // centre sits over the overlay that hosts it — the tap lands, the menu
    // closes, and the assertion below is what proves it.
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'Awaiting approval'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    // The server owns both the scoping and the totals, so the filter has to
    // travel as a parameter — a Dart-side `where` over one page would leave
    // "3 of 3" underneath a claim list the API actually returned 40 rows for.
    expect(script.lastQuery!['status'], 'pending');

    await tester.tap(
      find.byKey(const ValueKey('expense-status-filter')),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'All statuses'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], isNull);
  });

  testWidgets('an empty answer says what would appear here', (tester) async {
    script = ScriptedExpenses(idOf: (item) => item.id, items: const []);
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('No expense claims yet.'), findsOneWidget);
  });

  testWidgets('a failure on first load is a message, not a blank page', (
    tester,
  ) async {
    script.listError = unreachable;
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text(unreachable.message), findsOneWidget);
  });

  testWidgets('while the first page is in flight there is a spinner rather '
      'than "nothing yet"', (tester) async {
    final held = Completer<void>();
    script.holdList = held;

    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-loading')), findsOneWidget);
    expect(find.byKey(const ValueKey('list-empty')), findsNothing);

    held.complete();
    await advance(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    script.holdList = null;
  });
}
