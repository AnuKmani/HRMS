import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/departments/domain/department.dart';
import 'package:mobile/features/departments/presentation/department_form_screen.dart';

import '../../../support/phase4.dart';

/// A router with just enough of the app's real path table for the form to
/// navigate the way it does in production.
///
/// The form does not know it is under test — `context.go('/departments')` is
/// called either way, and a router that could not resolve it would throw and
/// fail the test for the wrong reason.
GoRouter formRouter(String initialLocation) => GoRouter(
  initialLocation: initialLocation,
  routes: [
    GoRoute(path: '/', builder: (_, _) => const SizedBox()),
    GoRoute(path: '/departments', builder: (_, _) => const SizedBox()),
    GoRoute(
      path: '/departments/new',
      builder: (_, _) => const DepartmentFormScreen(),
    ),
    GoRoute(
      path: '/departments/:id',
      builder: (_, state) => DepartmentFormScreen(
        departmentId: int.parse(state.pathParameters['id']!),
      ),
    ),
  ],
);

const invalid = ApiException(
  statusCode: 422,
  message: 'The given data was invalid.',
  errors: {'name': 'The name has already been taken.'},
);

const denied = ApiException(
  statusCode: 403,
  message: 'This action is unauthorized.',
);

void main() {
  late ScriptedDepartments script;

  setUp(() {
    script = ScriptedDepartments(
      idOf: (item) => item.id,
      items: [
        const Department(
          id: 7,
          name: 'Human Resources',
          code: 'HR',
          description: 'Hiring and policy.',
          status: 'inactive',
        ),
      ],
    );

    // The form discards the response and navigates, so a create only needs
    // a model to hand back.
    script.onSave = (_) =>
        const Department(id: 99, name: 'New', code: 'NEW', status: 'active');
  });

  Future<void> pumpForm(
    WidgetTester tester, {
    String at = '/departments/new',
  }) async {
    useTallScreen(tester);
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['departments.manage'],
        departments: script,
        child: MaterialApp.router(routerConfig: formRouter(at)),
      ),
    );
    await advance(tester);
  }

  /// Indices are stable because this form draws plain `LabeledTextField`s in
  /// a fixed order and no picker adds one: name, code, description.
  Future<void> fill(
    WidgetTester tester, {
    String name = 'Finance',
    String code = 'FIN',
    String description = '',
  }) async {
    await tester.enterText(find.byType(TextField).at(0), name);
    await tester.enterText(find.byType(TextField).at(1), code);
    await tester.enterText(find.byType(TextField).at(2), description);
  }

  testWidgets('a half-filled form is refused before anything is sent', (
    tester,
  ) async {
    await pumpForm(tester);

    await tester.tap(find.byKey(const ValueKey('form-save')));
    await advance(tester);

    expect(script.createCalls, 0);
    // Two fields, both required, both said out loud under the field they
    // belong to rather than in a toast that vanishes.
    expect(find.text('This field is required.'), findsNWidgets(2));
    expect(find.byKey(const ValueKey('form-error')), findsNothing);
  });

  testWidgets(
    'a valid create sends trimmed values and an empty description as null',
    (tester) async {
      await pumpForm(tester);

      await fill(
        tester,
        name: '  Finance  ',
        code: ' FIN ',
        description: '   ',
      );
      await tester.tap(find.byKey(const ValueKey('form-save')));
      await advance(tester);

      expect(script.createCalls, 1);
      expect(script.lastBody, {
        'name': 'Finance',
        'code': 'FIN',
        'description': null,
        'status': 'active',
      });

      // Navigated away rather than staying on a form that was already saved.
      expect(find.byKey(const ValueKey('form-save')), findsNothing);
    },
  );

  testWidgets('a 422 from the server lands on the field it names', (
    tester,
  ) async {
    await pumpForm(tester);
    await fill(tester);

    script.saveError = invalid;

    await tester.tap(find.byKey(const ValueKey('form-save')));
    await advance(tester);

    // Still on the form — the user has to be able to act on what it says.
    expect(find.byKey(const ValueKey('form-save')), findsOneWidget);
    expect(find.byKey(const ValueKey('form-error')), findsOneWidget);
    expect(find.text(invalid.message), findsOneWidget);
    expect(find.text('The name has already been taken.'), findsOneWidget);
    // A validation failure is not a lock-out, so it must not be drawn as one.
    expect(find.byKey(const ValueKey('form-forbidden')), findsNothing);

    // The banner is not sticky: a corrected second attempt starts clean.
    await tester.enterText(find.byType(TextField).at(0), 'Finance Two');
    await tester.tap(find.byKey(const ValueKey('form-save')));
    await advance(tester);

    expect(script.createCalls, 2);
    expect(find.byKey(const ValueKey('form-error')), findsNothing);
  });

  testWidgets('a 403 says so, and does not blame a field for it', (
    tester,
  ) async {
    await pumpForm(tester);
    await fill(tester);

    script.saveError = denied;

    await tester.tap(find.byKey(const ValueKey('form-save')));
    await advance(tester);

    expect(find.byKey(const ValueKey('form-forbidden')), findsOneWidget);
    expect(find.text(denied.message), findsOneWidget);
    // The lock icon is the point: nothing the user typed caused this.
    expect(find.byIcon(Icons.lock_outline), findsOneWidget);
    expect(script.createCalls, 1);
  });

  testWidgets('editing loads the record, then puts it back with the same id', (
    tester,
  ) async {
    await pumpForm(tester, at: '/departments/7');

    expect(script.findCalls, 1);
    expect(script.lastId, 7);

    await tester.pump();

    // Read the controllers rather than the widget tree: `find.text` matches
    // both a field's contents and its hint, and this field's hint is the same
    // sentence as its value, so any finder built on visible text would count
    // it twice and prove nothing about which one was loaded.
    expect(
      tester.widget<TextField>(find.byType(TextField).at(0)).controller?.text,
      'Human Resources',
    );
    expect(
      tester.widget<TextField>(find.byType(TextField).at(2)).controller?.text,
      'Hiring and policy.',
    );

    // Status came through as `inactive` — the chips must show that, not the
    // `active` a screen that ignored the response would have defaulted to.
    final inactiveChip = find.ancestor(
      of: find.text('Inactive'),
      matching: find.byType(ChoiceChip),
    );
    expect(tester.widget<ChoiceChip>(inactiveChip).selected, isTrue);

    await tester.tap(find.byKey(const ValueKey('form-save')));
    await advance(tester);

    expect(script.updateCalls, 1);
    expect(script.createCalls, 0);
    expect(script.lastId, 7);
    expect(script.lastBody!['status'], 'inactive');
    expect(script.lastBody!['name'], 'Human Resources');
  });
}
