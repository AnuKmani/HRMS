import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/loans/domain/loan.dart';
import 'package:mobile/features/loans/presentation/loan_form_screen.dart';

import '../../../support/phase8.dart';
import '../../../support/site_reports.dart' show pickDate, tapIn;

/// The paths the form can be on, because it walks away to the draft it just
/// made and a router that could not resolve that path would fail the test
/// for the wrong reason.
GoRouter loanFormRouter(String initialLocation) => GoRouter(
  initialLocation: initialLocation,
  routes: [
    GoRoute(path: '/loans', builder: (_, _) => const SizedBox()),
    GoRoute(path: '/loans/new', builder: (_, _) => const LoanFormScreen()),
    GoRoute(path: '/loans/:id', builder: (_, _) => const SizedBox()),
    GoRoute(
      path: '/loans/:id/edit',
      builder: (_, state) =>
          LoanFormScreen(loanId: int.parse(state.pathParameters['id']!)),
    ),
  ],
);

const invalidAmount = ApiException(
  statusCode: 422,
  message: 'The given data was invalid.',
  errors: {'principal_amount': 'The amount must be at least 500.'},
);

void main() {
  late ScriptedLoans script;

  setUp(() {
    script = ScriptedLoans(
      idOf: (item) => item.id,
      items: [loanRow(id: 7, status: Loan.statusDraft, reference: 'Deposit')],
    );
  });

  Future<void> pumpForm(
    WidgetTester tester, {
    required String location,
    List<String> permissions = const ['loans.create', 'loans.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase8(
        permissions: permissions,
        loans: script,
        child: MaterialApp.router(routerConfig: loanFormRouter(location)),
      ),
    );
    await advance(tester);
  }

  testWidgets('nobody is ever asked to look up their own employee id', (
    tester,
  ) async {
    await pumpForm(tester, location: '/loans/new');

    expect(find.byKey(const ValueKey('loan-submit')), findsOneWidget);
    expect(find.text('Employee'), findsNothing);
    expect(find.byKey(const ValueKey('loan-employee')), findsNothing);

    await tapIn(tester, find.byKey(const ValueKey('loan-principal')));
    await tester.enterText(
      find.byKey(const ValueKey('loan-principal')),
      '12000.00',
    );
    await tester.enterText(
      find.byKey(const ValueKey('loan-installments')),
      '12',
    );
    await pickDate(tester, const ValueKey('loan-start'));

    await tapIn(tester, find.byKey(const ValueKey('loan-submit')));
    await advance(tester);
    await tester.pumpAndSettle();

    expect(script.createCalls, 1);

    final body = script.lastCreated!;
    expect(body.containsKey('employee_id'), isFalse);
    expect(body['principal_amount'], '12000.00');
    expect(body['number_of_installments'], '12');
    expect(body['loan_type'], 'loan');
    expect(body['start_date'], DateTime.now().toString().split(' ')[0]);

    // …and none of the lifecycle's fields either: status, balance and
    // approver belong to `LoanService`, not to a form.
    expect(body.containsKey('status'), isFalse);
    expect(body.containsKey('outstanding_balance'), isFalse);

    // The form walked away to the draft it just made.
    expect(find.byKey(const ValueKey('loan-submit')), findsNothing);
  });

  testWidgets('the kind of advance is what it says it is', (tester) async {
    await pumpForm(tester, location: '/loans/new');

    await tapIn(tester, find.byKey(const ValueKey('status-salary_advance')));
    await tester.pump();

    await tester.enterText(
      find.byKey(const ValueKey('loan-principal')),
      '5000',
    );
    await tester.enterText(
      find.byKey(const ValueKey('loan-installments')),
      '5',
    );

    await tapIn(tester, find.byKey(const ValueKey('loan-submit')));
    await advance(tester);
    await tester.pumpAndSettle();

    expect(script.lastCreated!['loan_type'], 'salary_advance');

    // Blank is not sent at all, so the server's own even split applies.
    expect(script.lastCreated!.containsKey('installment_amount'), isFalse);
  });

  testWidgets('a refusal about a field is drawn against that field', (
    tester,
  ) async {
    script.actionError = invalidAmount;

    await pumpForm(tester, location: '/loans/new');

    await tester.enterText(find.byKey(const ValueKey('loan-principal')), '10');
    await tester.enterText(
      find.byKey(const ValueKey('loan-installments')),
      '1',
    );

    await tapIn(tester, find.byKey(const ValueKey('loan-submit')));
    await advance(tester);

    expect(find.text('The amount must be at least 500.'), findsOneWidget);
    expect(find.byKey(const ValueKey('form-error')), findsNothing);
  });

  testWidgets('editing is the same form, without an employee field', (
    tester,
  ) async {
    await pumpForm(
      tester,
      location: '/loans/7/edit',
      permissions: const ['loans.view'],
    );
    await advance(tester);

    // Drafts are the only rows that can be corrected, and correcting one
    // needs the read grant rather than `loans.create`.
    expect(find.byKey(const ValueKey('loan-submit')), findsOneWidget);
    expect(find.text('Save changes'), findsOneWidget);

    // Read from the field's own controller rather than by text: the hint is
    // the same string as the value, so `find.text` sees both.
    expect(
      tester
          .widget<EditableText>(
            find.descendant(
              of: find.byKey(const ValueKey('loan-principal')),
              matching: find.byType(EditableText),
            ),
          )
          .controller
          .text,
      '12000.00',
    );

    await tapIn(tester, find.byKey(const ValueKey('loan-reference')));
    await tester.enterText(
      find.byKey(const ValueKey('loan-reference')),
      'Clear',
    );

    await tapIn(tester, find.byKey(const ValueKey('loan-submit')));
    await advance(tester);
    await tester.pumpAndSettle();

    expect(script.updateCalls, 1);
    expect(script.lastBody!['reference'], 'Clear');
    expect(script.lastBody!.containsKey('employee_id'), isFalse);
  });
}
