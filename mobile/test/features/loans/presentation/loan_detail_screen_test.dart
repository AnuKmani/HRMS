import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/loans/domain/loan.dart';
import 'package:mobile/features/loans/presentation/loan_detail_screen.dart';

import '../../../support/phase8.dart';

const ApiException selfDecision = ApiException(
  statusCode: 403,
  message: 'You cannot approve your own loan.',
);

const ApiException alreadyDecided = ApiException(
  statusCode: 409,
  message: 'Only a loan awaiting a decision can be approved.',
);

void main() {
  late ScriptedLoans script;

  setUp(() {
    script = ScriptedLoans(
      idOf: (item) => item.id,
      items: [
        loanRow(id: 1, status: Loan.statusDraft, remarks: 'For a deposit.'),
        loanRow(id: 2, status: Loan.statusPending, reference: 'ADV-2'),
        loanRow(
          id: 3,
          name: 'Meera Nair',
          status: Loan.statusActive,
          principal: '6000.00',
          outstanding: '4000.00',
          repaid: '2000.00',
          count: 6,
          each: '1000.00',
          installments: [
            loanInstallment(
              sequence: 1,
              status: LoanInstallment.statusDeducted,
            ),
            loanInstallment(sequence: 2),
            loanInstallment(sequence: 3),
          ],
        ),
      ],
    );
  });

  Future<void> pumpDetail(
    WidgetTester tester, {
    int id = 1,
    List<String> permissions = const ['loans.view', 'loans.create'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        loans: script,
        child: MaterialApp(home: LoanDetailScreen(loanId: id)),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without loans.view is never shown a debt', (
    tester,
  ) async {
    await pumpDetail(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.findCalls, 0);
  });

  testWidgets('the three figures are shown together and drawn from the '
      'balance', (tester) async {
    await pumpDetail(tester, id: 3);

    expect(find.text('INR 6,000.00'), findsOneWidget); // advanced
    expect(find.text('INR 2,000.00'), findsOneWidget); // repaid
    expect(find.text('INR 4,000.00'), findsOneWidget); // still owing
    expect(find.text('33% repaid'), findsOneWidget);

    // The progress bar follows the balance, not a count of `deducted` rows:
    // one of six payments was taken and a skipped one must not be counted.
    final progress = tester.widget<LinearProgressIndicator>(
      find.byKey(const ValueKey('loan-progress')),
    );
    expect(progress.value, closeTo(1 / 3, 0.001));

    expect(find.byKey(const ValueKey('installment-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('installment-2')), findsOneWidget);
    expect(find.text('Deducted'), findsOneWidget);
  });

  testWidgets('a draft can be submitted, and the button is the borrower’s '
      'half of the question', (tester) async {
    await pumpDetail(tester, id: 1, permissions: const ['loans.view']);
    expect(find.byKey(const ValueKey('submit-loan')), findsNothing);

    await pumpDetail(tester, id: 1);
    expect(find.byKey(const ValueKey('submit-loan')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('submit-loan')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Submit').last);
    await advance(tester);

    expect(script.lastTransition, 'submit');
    expect(script.lastTransitionId, 1);
  });

  testWidgets('a pending loan can be answered only with loans.approve', (
    tester,
  ) async {
    await pumpDetail(tester, id: 2, permissions: const ['loans.view']);
    expect(find.byKey(const ValueKey('approve-loan')), findsNothing);
    expect(find.byKey(const ValueKey('reject-loan')), findsNothing);

    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['loans.view', 'loans.approve'],
    );

    expect(find.byKey(const ValueKey('approve-loan')), findsOneWidget);
    expect(find.byKey(const ValueKey('reject-loan')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('approve-loan')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Approve').last);
    await advance(tester);

    expect(script.lastTransition, 'approve');
    expect(script.lastTransitionId, 2);
    expect(script.lastRemarks, isNull);
  });

  testWidgets('refusing asks why, and sends what it was told', (tester) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['loans.view', 'loans.approve'],
    );

    await tester.tap(find.byKey(const ValueKey('reject-loan')));
    await tester.pumpAndSettle();

    // A refusal with no reason recorded is a decision nobody can act on, so
    // the dialog will not close until one is given.
    await tester.tap(find.text('Refuse').last);
    await tester.pumpAndSettle();

    expect(script.lastTransition, isNull);
    expect(find.text('Say why before confirming.'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Remarks *'),
      'Advance exceeds policy.',
    );
    await tester.tap(find.text('Refuse').last);
    await advance(tester);

    expect(script.lastTransition, 'reject');
    expect(script.lastRemarks, 'Advance exceeds policy.');
  });

  testWidgets('a refusal from the server is shown as written', (tester) async {
    script.actionError = alreadyDecided;

    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['loans.view', 'loans.approve'],
    );

    await tester.tap(find.byKey(const ValueKey('approve-loan')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Approve').last);
    await advance(tester);

    expect(
      find.text('Only a loan awaiting a decision can be approved.'),
      findsOneWidget,
    );
  });

  // A payment the pay run could only partly take is the shape the
  // net-salary floor produces, so the schedule has to say which part of it
  // is still owed rather than printing the scheduled figure as though the
  // whole of it had moved.
  testWidgets('a payment the run only partly took says what is left', (
    tester,
  ) async {
    script = ScriptedLoans(
      idOf: (item) => item.id,
      items: [
        loanRow(
          id: 4,
          status: Loan.statusActive,
          principal: '6000.00',
          outstanding: '5000.00',
          repaid: '1000.00',
          count: 6,
          each: '1000.00',
          installments: [
            loanInstallment(
              sequence: 1,
              status: LoanInstallment.statusPartiallyDeducted,
              deductedAmount: '400.00',
              remainingAmount: '600.00',
            ),
            loanInstallment(sequence: 2),
          ],
        ),
      ],
    );

    await pumpDetail(tester, id: 4);

    expect(find.byKey(const ValueKey('installment-1')), findsOneWidget);
    expect(find.text('Partly deducted'), findsOneWidget);

    // 600.00 is what is left of a 1,000.00 payment 400.00 of which has
    // already been taken - the figure the next run is offered first.
    expect(find.text('INR 600.00'), findsOneWidget);
  });

  // Two tests rather than one: `LoanDetailScreen` keeps its state when the
  // same route is re-pumped with a different id, so a single test that
  // looked at the draft first and then at the pending row would still be
  // holding the draft.
  testWidgets('a draft offers the way to correct it', (tester) async {
    await pumpDetail(tester, id: 1);
    expect(find.byKey(const ValueKey('edit-loan')), findsOneWidget);
  });

  testWidgets('anything past the draft does not', (tester) async {
    await pumpDetail(
      tester,
      id: 2,
      permissions: const ['loans.view', 'loans.create', 'loans.approve'],
    );
    expect(find.byKey(const ValueKey('edit-loan')), findsNothing);
  });
}
