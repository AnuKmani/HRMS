import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/expenses/domain/expense.dart';
import 'package:mobile/features/expenses/presentation/expense_detail_screen.dart';

import '../../../support/phase9.dart';
import '../../../support/site_reports.dart' show tapIn;

/// The path table the detail screen navigates, and nothing else.
///
/// `Edit` ends in `context.push('/expenses/{id}/edit')`, and a screen pumped
/// under a bare `MaterialApp` would throw on `GoRouter.of` for a reason that
/// has nothing to do with the buttons under test.
GoRouter detailRouter(String location) => GoRouter(
  initialLocation: location,
  routes: [
    GoRoute(
      path: '/expenses/:id',
      builder: (_, state) => ExpenseDetailScreen(
        expenseId: int.parse(state.pathParameters['id']!),
      ),
    ),
    GoRoute(
      path: '/expenses/:id/edit',
      builder: (_, state) =>
          Scaffold(body: Text('editing-${state.pathParameters['id']}')),
    ),
  ],
);

const ApiException alreadySubmitted = ApiException(
  statusCode: 409,
  message: 'Only a draft claim can be submitted.',
);

void main() {
  late ScriptedExpenses script;

  setUp(() {
    script = ScriptedExpenses(
      idOf: (item) => item.id,
      items: [
        expenseRow(id: 1, status: Expense.statusDraft, requiresReceipt: true),
        expenseRow(id: 2, status: Expense.statusPending, chain: true),
        expenseRow(id: 3, status: Expense.statusApproved, amount: '1000.00'),
        expenseRow(
          id: 4,
          status: Expense.statusDraft,
          receipts: [expenseReceipt(id: 10, expenseId: 4)],
          receiptCount: 1,
        ),
      ],
    );
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 1,
    List<String> permissions = const ['expenses.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase9(
        permissions: permissions,
        expenses: script,
        child: MaterialApp.router(routerConfig: detailRouter('/expenses/$id')),
      ),
    );
    await advance(tester);
  }

  Future<void> dialogButton(WidgetTester tester, String label) async {
    // Scoped to the dialog: the screen behind it holds buttons with the
    // same words, and a bare `find` would be choosing between two.
    await tapIn(
      tester,
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.widgetWithText(FilledButton, label),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('a session without expenses.view is never shown a claim', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
  });

  testWidgets('a claim nobody has seen yet offers to submit, edit and '
      'withdraw — and nothing else', (tester) async {
    await pumpDetail(tester, id: 1);

    // The figure is printed by `Money` from the server's decimal string: it
    // is the one number on this screen a person compares against paper.
    expect(find.text('INR 250.00'), findsOneWidget);
    expect(find.text('Draft'), findsOneWidget);

    expect(find.byKey(const ValueKey('submit-expense')), findsOneWidget);
    expect(find.byKey(const ValueKey('edit-expense')), findsOneWidget);
    expect(find.byKey(const ValueKey('cancel-expense')), findsOneWidget);

    // Nobody may sign off a claim that has not been submitted.
    expect(find.byKey(const ValueKey('approve-expense')), findsNothing);
    expect(find.byKey(const ValueKey('reject-expense')), findsNothing);
  });

  testWidgets('a claim in the chain is only signable by expenses.approve', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['expenses.view', 'expenses.create'],
    );

    expect(find.text('Awaiting approval'), findsOneWidget);
    expect(find.byKey(const ValueKey('approve-expense')), findsNothing);
    expect(find.byKey(const ValueKey('reject-expense')), findsNothing);
    // The claimant still owns the withdrawal.
    expect(find.byKey(const ValueKey('cancel-expense')), findsOneWidget);

    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['expenses.view', 'expenses.approve'],
    );

    expect(find.byKey(const ValueKey('approve-expense')), findsOneWidget);
    expect(find.byKey(const ValueKey('reject-expense')), findsOneWidget);
    // An approver is not the claimant: their action is the decision, so the
    // "withdraw it instead" button steps aside rather than offering a 403.
    expect(find.byKey(const ValueKey('cancel-expense')), findsNothing);
  });

  testWidgets('a settled claim offers nothing to decide', (tester) async {
    await pumpDetail(
      tester,
      id: 3,
      permissions: const ['expenses.view', 'expenses.approve'],
    );

    expect(find.text('Approved'), findsOneWidget);
    expect(find.byKey(const ValueKey('submit-expense')), findsNothing);
    expect(find.byKey(const ValueKey('edit-expense')), findsNothing);
    expect(find.byKey(const ValueKey('cancel-expense')), findsNothing);
    expect(find.byKey(const ValueKey('approve-expense')), findsNothing);
  });

  testWidgets('the approval chain is named, in order, with who was to '
      'answer', (tester) async {
    await pumpDetail(tester, id: 2);

    expect(find.text('Approval'), findsOneWidget);
    expect(find.text('1. Standard expense approval'), findsOneWidget);
    // A resolved person wins over the phrase: the chain pinned "your
    // reporting manager" to an actual employee at submit time, and naming
    // the role beside it would contradict the row it is describing.
    expect(find.text('Sara Iqbal'), findsOneWidget);
    expect(find.text('2. Finance sign-off'), findsOneWidget);
    expect(find.text('Anyone with expenses.manage'), findsOneWidget);
  });

  testWidgets('a refusal with no reason is refused before it is sent', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['expenses.view', 'expenses.approve'],
    );

    await tapIn(tester, find.byKey(const ValueKey('reject-expense')));
    await tester.pumpAndSettle();

    await dialogButton(tester, 'Reject');

    // The API answers 422 without remarks anyway; refusing here instead
    // keeps the reason where the person can still type it.
    expect(find.text('Say why before confirming.'), findsOneWidget);
    expect(script.lastTransition, isNull);

    await tester.enterText(find.byType(TextField), 'Not on this project.');
    await dialogButton(tester, 'Reject');

    expect(script.lastTransition, 'reject');
    expect(script.lastTransitionId, 2);
    expect(script.lastRemarks, 'Not on this project.');
  });

  testWidgets('an approval carries its optional remarks through', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['expenses.view', 'expenses.approve'],
    );

    await tapIn(tester, find.byKey(const ValueKey('approve-expense')));
    await tester.pumpAndSettle();

    expect(find.byType(AlertDialog), findsOneWidget);

    await dialogButton(tester, 'Approve');

    expect(script.lastTransition, 'approve');
    expect(script.lastTransitionId, 2);
  });

  testWidgets('a refusal the server still makes is a banner, not a '
      'mystery', (tester) async {
    await pumpDetail(tester, id: 1);
    script.actionError = alreadySubmitted;

    await tapIn(tester, find.byKey(const ValueKey('submit-expense')));
    await tester.pumpAndSettle();
    await dialogButton(tester, 'Submit');

    expect(script.lastTransition, 'submit');
    expect(find.byKey(const ValueKey('expense-banner')), findsOneWidget);
    expect(find.text(alreadySubmitted.message), findsOneWidget);
  });

  testWidgets('evidence is required before this category will let a claim '
      'go, and the screen says so', (tester) async {
    await pumpDetail(tester, id: 1);

    expect(find.byKey(const ValueKey('receipt-required')), findsOneWidget);
    expect(
      find.text(
        'This category needs a receipt before the claim can be submitted.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('receipts may be added to and taken from a draft only', (
    tester,
  ) async {
    await pumpDetail(tester, id: 4);

    expect(find.text('1 receipt'), findsOneWidget);
    expect(find.byKey(const ValueKey('receipt-10')), findsOneWidget);
    expect(find.byKey(const ValueKey('view-receipt-10')), findsOneWidget);
    expect(find.byKey(const ValueKey('add-receipt')), findsOneWidget);
    expect(find.byKey(const ValueKey('remove-receipt-10')), findsOneWidget);

    // A claim in the chain is frozen: evidence arriving after the approver
    // has read the claim is evidence they never saw.
    await pumpDetail(tester, id: 2);

    expect(find.byKey(const ValueKey('add-receipt')), findsNothing);
    expect(find.byKey(const ValueKey('remove-receipt-10')), findsNothing);
  });

  testWidgets('a receipt is photographed and filed without ever touching '
      'a path', (tester) async {
    await pumpDetail(tester, id: 4);

    await tapIn(tester, find.byKey(const ValueKey('add-receipt')));
    await tester.pumpAndSettle();

    await tapIn(tester, find.byIcon(Icons.camera_alt));
    await tester.pumpAndSettle();

    await tapIn(tester, find.widgetWithText(FilledButton, 'Use photo'));
    await advance(tester);

    expect(script.receiptCalls, 1);
    expect(script.lastReceiptExpenseId, 4);

    // Two now, and the upload was bytes plus a name — no URL, no location.
    expect(find.text('2 receipts'), findsOneWidget);
  });

  testWidgets('removing a receipt takes the row away and says so', (
    tester,
  ) async {
    await pumpDetail(tester, id: 4);

    await tapIn(tester, find.byKey(const ValueKey('remove-receipt-10')));
    await tester.pumpAndSettle();

    await dialogButton(tester, 'Remove');

    expect(script.lastRemovedReceiptId, 10);
    expect(find.byKey(const ValueKey('receipt-10')), findsNothing);
    expect(find.text('No receipts yet'), findsOneWidget);
  });

  testWidgets('opening a receipt reads it by id on its own claim', (
    tester,
  ) async {
    await pumpDetail(tester, id: 4);

    await tapIn(tester, find.byKey(const ValueKey('view-receipt-10')));
    await advance(tester);

    expect(script.lastReadReceiptId, 10);
    expect(find.byType(AlertDialog), findsOneWidget);

    // Scoped to the dialog: the row behind it carries the same name, and a
    // bare `find.text` would be counting both.
    expect(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.text('IMG_0142.jpg'),
      ),
      findsOneWidget,
    );

    final image = find.descendant(
      of: find.byType(AlertDialog),
      matching: find.byType(Image),
    );
    expect(image, findsOneWidget);
  });
}
