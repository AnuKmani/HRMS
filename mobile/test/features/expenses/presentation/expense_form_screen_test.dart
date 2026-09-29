import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/config/client_settings.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/expenses/presentation/expense_form_screen.dart';

import '../../../support/phase9.dart';
import '../../../support/site_reports.dart' show pickDate, tapIn, type;

/// The path table a form screen needs, and nothing else.
///
/// `save` ends in `context.go('/expenses/{id}')`, and a screen pumped under a
/// bare `MaterialApp` would throw on `GoRouter.of` for a reason that has
/// nothing to do with the save under test — so the destination exists here
/// as a stub that says what it was handed.
GoRouter formRouter(String location) => GoRouter(
  initialLocation: location,
  routes: [
    GoRoute(
      path: '/expenses/new',
      builder: (_, state) => const ExpenseFormScreen(),
    ),
    GoRoute(
      path: '/expenses/:id/edit',
      builder: (_, state) =>
          ExpenseFormScreen(expenseId: int.parse(state.pathParameters['id']!)),
    ),
    GoRoute(
      path: '/expenses/:id',
      builder: (_, state) =>
          Scaffold(body: Text('claim-${state.pathParameters['id']}')),
    ),
  ],
);

const ApiException badAmount = ApiException(
  statusCode: 422,
  message: 'The given data was invalid.',
  errors: <String, String>{'amount': 'The amount must be greater than 0.'},
);

const ApiException badEverywhere = ApiException(
  statusCode: 422,
  message: 'The given data was invalid.',
  errors: <String, String>{
    'expense_date': 'The date may not be in the future.',
    'expense_category_id': 'Choose an active category.',
    'currency': 'Enter the currency as three letters.',
    'description': 'Describe what it was for.',
  },
);

const ApiException mayNotFile = ApiException(
  statusCode: 403,
  message: 'You may not raise an expense claim.',
);

void main() {
  late ScriptedExpenses script;

  setUp(() {
    script = ScriptedExpenses(
      idOf: (item) => item.id,
      items: [expenseRow(id: 1, requiresReceipt: true)],
      fallback: expenseRow(id: 42),
    );
    script.categoryRows = [
      expenseCategory(id: 1, name: 'Travel', requiresReceipt: true),
      expenseCategory(
        id: 4,
        name: 'Food',
        requiresReceipt: false,
        maximumAmount: '1000.00',
      ),
    ];
  });

  Future<void> pumpForm(
    WidgetTester tester, {
    int? expenseId,
    List<String> permissions = const ['expenses.view', 'expenses.create'],
    ScriptedClientSettings? clientSettings,
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase9(
        permissions: permissions,
        expenses: script,
        clientSettings: clientSettings,
        child: MaterialApp.router(
          routerConfig: formRouter(
            expenseId == null ? '/expenses/new' : '/expenses/$expenseId/edit',
          ),
        ),
      ),
    );
    await advance(tester);
  }

  /// The currency box itself, not the label and helper wrapped around it.
  TextField currencyField(WidgetTester tester) => tester.widget<TextField>(
    find.descendant(
      of: find.byKey(const ValueKey('expense-currency')),
      matching: find.byType(TextField),
    ),
  );

  Future<void> chooseCategory(
    WidgetTester tester, {
    String label = 'Travel',
  }) async {
    await tapIn(tester, find.byKey(const ValueKey('expense-category')));
    await tester.pumpAndSettle();

    await tapIn(tester, find.widgetWithText(ListTile, label));
    await tester.pumpAndSettle();
  }

  testWidgets('a session without expenses.create is offered no form', (
    tester,
  ) async {
    await pumpForm(tester, permissions: const ['expenses.view']);

    expect(find.text('You may not raise an expense claim.'), findsOneWidget);
    expect(find.byKey(const ValueKey('save-expense')), findsNothing);
    expect(script.createCalls, 0);
  });

  testWidgets('correcting a draft needs expenses.update, not create', (
    tester,
  ) async {
    await pumpForm(
      tester,
      expenseId: 1,
      permissions: const ['expenses.view', 'expenses.create'],
    );

    expect(find.text('You may not edit this draft.'), findsOneWidget);
    // Refused before the fetch: a claim this session may not open should
    // never have been asked for.
    expect(script.findCalls, 0);
  });

  testWidgets('a new claim sends the person\'s own identity, never one they '
      'typed', (tester) async {
    await pumpForm(tester);

    await pickDate(tester, const ValueKey('expense-date'));
    await chooseCategory(tester);
    await type(tester, const ValueKey('expense-amount'), '250.00');
    // No typing here on purpose: with one currency configured the box is
    // filled in and closed, so what is asserted below is the *default* the
    // server configured rather than anything this test put in.
    await type(tester, const ValueKey('expense-description'), 'Taxi fare.');

    await tapIn(tester, find.byKey(const ValueKey('save-expense')));
    await advance(tester);

    expect(script.createCalls, 1);

    final body = script.lastBody!;

    // `employee_id` and `status` are both `prohibited` in
    // `StoreExpenseRequest`. The claim belongs to whoever is signed in and
    // starts life as a draft, so a form that offered them would be inviting
    // a 422 in exchange for nothing.
    expect(body.keys, isNot(contains('employee_id')));
    expect(body.keys, isNot(contains('status')));
    expect(body['expense_category_id'], 1);
    expect(body['amount'], '250.00');
    // The configured company currency, arrived at by asking the server
    // rather than by reading it out of this file.
    expect(body['currency'], 'AED');
    expect(body['description'], 'Taxi fare.');
    expect(body['expense_date'], isNotEmpty);

    // Landed on the claim it just made, with its submit button.
    expect(find.text('claim-1'), findsOneWidget);
  });

  testWidgets('choosing a category says what its rules are, while the claim '
      'is being written', (tester) async {
    await pumpForm(tester);
    expect(find.byKey(const ValueKey('expense-category-rules')), findsNothing);

    await chooseCategory(tester);

    expect(
      find.byKey(const ValueKey('expense-category-rules')),
      findsOneWidget,
    );
    expect(find.text('Needs a receipt'), findsOneWidget);

    await chooseCategory(tester, label: 'Food');
    expect(find.text('Up to 1000.00'), findsOneWidget);
  });

  testWidgets('editing shows the rules the draft was filed under, from the '
      'claim itself', (tester) async {
    await pumpForm(
      tester,
      expenseId: 1,
      permissions: const ['expenses.view', 'expenses.update'],
    );

    // Before anything else loads: a draft opened on a slow connection still
    // says the category needs paper, because the claim carries its own copy.
    expect(find.text('Needs a receipt'), findsOneWidget);
    expect(
      find.text('Taxi from the airport to the site office'),
      findsOneWidget,
    );
    expect(find.text('250.00'), findsOneWidget);
  });

  testWidgets('the amount a server refuses is drawn against the amount', (
    tester,
  ) async {
    await pumpForm(tester);

    script.saveError = badAmount;
    await tapIn(tester, find.byKey(const ValueKey('save-expense')));
    await advance(tester);

    expect(script.createCalls, 1);

    // Field errors go to fields and nowhere else: the amount is the one this
    // claim is about, and a banner would make the reader hunt for which of
    // five inputs the sentence belonged to.
    expect(find.text('The amount must be greater than 0.'), findsOneWidget);
    expect(find.byKey(const ValueKey('expense-form-banner')), findsNothing);
  });

  testWidgets('a batch of refusals is drawn against the fields they name', (
    tester,
  ) async {
    await pumpForm(tester);

    script.saveError = badEverywhere;
    await tapIn(tester, find.byKey(const ValueKey('save-expense')));
    await advance(tester);

    expect(find.text('The date may not be in the future.'), findsOneWidget);
    expect(find.text('Choose an active category.'), findsOneWidget);
    expect(find.text('Enter the currency as three letters.'), findsOneWidget);
    expect(find.text('Describe what it was for.'), findsOneWidget);

    // Every one of them is a rule the backend owns. Duplicating them in Dart
    // would mean two lists of rules that drift apart the day one changes.
    expect(find.byKey(const ValueKey('expense-form-banner')), findsNothing);
  });

  testWidgets('a refusal that is not about the form is a banner, and the '
      'form stops offering to save', (tester) async {
    await pumpForm(tester);

    script.saveError = mayNotFile;
    await tapIn(tester, find.byKey(const ValueKey('save-expense')));
    await advance(tester);

    expect(find.byKey(const ValueKey('expense-form-banner')), findsOneWidget);
    expect(find.text('You may not raise an expense claim.'), findsOneWidget);

    final save = tester.widget<FilledButton>(
      find.byKey(const ValueKey('save-expense')),
    );
    expect(save.onPressed, isNull);
    expect(script.createCalls, 1);
  });

  /* ------------------------------------------------- where the currency */

  testWidgets('one configured currency is shown, not offered', (tester) async {
    await pumpForm(tester);

    // Asked the server, got one code, and closed the box: a choice between
    // one option is an invitation to type over it.
    expect(currencyField(tester).enabled, isFalse);
    expect(find.text('AED'), findsOneWidget);
    expect(
      find.text(
        'The company currency. File it as you spent it — nothing is converted.',
      ),
      findsOneWidget,
    );

    // Waiting on the configuration is not the same as waiting forever: the
    // answer is in, so saving is allowed again.
    final save = tester.widget<FilledButton>(
      find.byKey(const ValueKey('save-expense')),
    );
    expect(save.onPressed, isNotNull);
  });

  testWidgets('several configured currencies become a menu the form keeps '
      'to', (tester) async {
    await pumpForm(
      tester,
      clientSettings: ScriptedClientSettings(
        settings: const ClientSettings(
          defaultCurrency: 'USD',
          supportedCurrencies: ['USD', 'EUR'],
        ),
      ),
    );

    expect(currencyField(tester).enabled, isTrue);
    expect(find.text('USD'), findsOneWidget);
    expect(
      find.text('This company accepts USD, EUR. No conversion is applied.'),
      findsOneWidget,
    );

    await pickDate(tester, const ValueKey('expense-date'));
    await chooseCategory(tester);
    await type(tester, const ValueKey('expense-amount'), '250.00');
    await type(tester, const ValueKey('expense-currency'), 'EUR');
    await type(tester, const ValueKey('expense-description'), 'Taxi fare.');

    await tapIn(tester, find.byKey(const ValueKey('save-expense')));
    await advance(tester);

    expect(script.createCalls, 1);
    expect(script.lastCreated!['currency'], 'EUR');
  });

  testWidgets('a draft keeps the code it was filed with, whatever the '
      'setting says now', (tester) async {
    final settings = ScriptedClientSettings();

    await pumpForm(
      tester,
      expenseId: 1,
      permissions: const ['expenses.view', 'expenses.update'],
      clientSettings: settings,
    );

    // The configuration says AED. This draft was filed in INR, and an edit
    // does not re-price a record: no conversion happens anywhere in this
    // app, least of all underneath somebody correcting a description.
    expect(find.text('INR'), findsOneWidget);
    expect(currencyField(tester).enabled, isFalse);
    expect(
      settings.loadCalls,
      0,
      reason: 'Opening a draft needs no configuration.',
    );

    await type(tester, const ValueKey('expense-description'), 'Corrected.');
    await tapIn(tester, find.byKey(const ValueKey('save-expense')));
    await advance(tester);

    expect(
      script.lastCreated,
      isNotNull,
      reason: 'The correction must have gone out as an update.',
    );
    expect(script.lastCreated!['currency'], 'INR');
  });

  testWidgets('a settings call that fails opens the box instead of blocking '
      'the claim', (tester) async {
    final settings = ScriptedClientSettings()
      ..failure = const ApiException(
        statusCode: 500,
        message: 'Something went wrong.',
      );

    await pumpForm(tester, clientSettings: settings);

    expect(settings.loadCalls, 1);
    expect(currencyField(tester).enabled, isTrue);
    expect(currencyField(tester).controller!.text, isEmpty);

    // Still no configuration, but there is nothing left to wait for — and
    // the server is the one that decides whether a code is acceptable, so
    // an unreachable settings call is not a reason to hold the form shut.
    final save = tester.widget<FilledButton>(
      find.byKey(const ValueKey('save-expense')),
    );
    expect(save.onPressed, isNotNull);

    await type(tester, const ValueKey('expense-currency'), 'AED');
    expect(currencyField(tester).controller!.text, 'AED');
  });
}
