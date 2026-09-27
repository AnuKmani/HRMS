import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/employees/domain/employee.dart';
import 'package:mobile/features/employees/presentation/employees_list_screen.dart';

import '../../../support/phase4.dart';

/// Three different people, so an assertion on "one row" cannot be satisfied
/// by three identical ones hiding a duplicated result.
Employee employee({required int id, String? name}) => Employee(
      id: id,
      employeeCode: 'EMP-$id',
      firstName: 'Person',
      lastName: '$id',
      fullName: name ?? 'Person $id',
      employmentType: 'permanent',
      employmentStatus: 'active',
    );

void main() {
  late ScriptedEmployees script;

  setUp(() {
    script = ScriptedEmployees(
      idOf: (item) => item.id,
      items: [for (var i = 1; i <= 3; i++) employee(id: i)],
    );
  });

  Future<void> pumpList(WidgetTester tester) async {
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['employees.view', 'employees.create'],
        employees: script,
        child: const MaterialApp(home: EmployeesListScreen()),
      ),
    );
  }

  group('the four states a list can be in', () {
    testWidgets('draws a spinner while the first page is in flight',
        (tester) async {
      script.holdList = Completer<void>();

      await pumpList(tester);
      await tester.pump();

      // Before the answer arrives there is exactly one honest thing to draw.
      // Showing the empty state here would tell a user "no employees exist"
      // a moment before three of them show up.
      expect(find.byKey(const ValueKey('list-loading')), findsOneWidget);
      expect(find.byKey(const ValueKey('list-empty')), findsNothing);

      script.holdList!.complete();
      await advance(tester);

      expect(find.byKey(const ValueKey('list-loading')), findsNothing);
      expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
      expect(find.text('Person 1'), findsOneWidget);
    });

    testWidgets('says the list is empty rather than drawing nothing',
        (tester) async {
      script.items.clear();

      await pumpList(tester);
      await advance(tester);

      expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
      expect(find.text('No employees match these filters.'), findsOneWidget);
      expect(find.byKey(const ValueKey('list-error')), findsNothing);
    });

    testWidgets('shows the failure with a way out, and retries',
        (tester) async {
      script.listError = unreachable;

      await pumpList(tester);
      await advance(tester);

      expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
      expect(find.text(unreachable.message), findsOneWidget);
      // Nothing was drawn from the failed request: there are no rows to
      // confuse with a successful-but-empty answer.
      expect(find.byKey(const ValueKey('list-results')), findsNothing);

      // The error was consumed by the first attempt, so the retry succeeds.
      await tester.tap(find.byKey(const ValueKey('list-retry')));
      await advance(tester);

      expect(find.byKey(const ValueKey('list-error')), findsNothing);
      expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
      expect(script.listCalls, 2);
    });

    testWidgets('keeps the rows on screen when a refresh fails',
        (tester) async {
      await pumpList(tester);
      await advance(tester);

      expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

      script.listError = unreachable;

      // Pull-to-refresh is a reload of a list that already has content; a
      // failure must apologise on top rather than blank what was being read.
      await tester.fling(
        find.byKey(const ValueKey('list-results')),
        const Offset(0, 300),
        1000,
      );
      await advance(tester, frames: 8);

      // Exactly one reload — a refresh that never left the screen would leave
      // the previous answer standing, which is the same as "it worked".
      expect(script.listCalls, 2);

      expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
      expect(find.text('Person 1'), findsOneWidget);
      expect(
        find.text(unreachable.message),
        findsOneWidget,
        reason: 'the failed refresh must still be said out loud',
      );
    });
  });

  group('filters', () {
    testWidgets('a search is sent as `search` and kept out of the list keys',
        (tester) async {
      await pumpList(tester);
      await advance(tester);

      expect(script.lastQuery, isNot(contains('search')));

      await tester.enterText(
        find.byKey(const ValueKey('list-search')),
        '  ravi  ',
      );
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await advance(tester);

      // Trimmed: a stray space from a phone keyboard must not become part of
      // a query the API is then asked to match literally.
      expect(script.lastQuery!['search'], 'ravi');
    });

    testWidgets('the status filter sends only the status chosen',
        (tester) async {
      await pumpList(tester);
      await advance(tester);

      await tester.tap(find.byKey(const ValueKey('status-filter')));
      await tester.pumpAndSettle();

      await tester.tap(find.text('On leave').last);
      await tester.pumpAndSettle();

      expect(script.lastQuery!['employment_status'], 'on_leave');
      expect(script.lastQuery, isNot(contains('search')));

      // Clearing it removes the key instead of sending `status=`: those are
      // two different questions to the API.
      await tester.tap(find.byKey(const ValueKey('status-filter')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('All statuses'));
      await tester.pumpAndSettle();

      expect(script.lastQuery, isNot(contains('employment_status')));
    });
  });

  testWidgets('asks for the next page when there is one', (tester) async {
    // Fifteen rows plus the button only fit — and so only get *built* — on a
    // surface that can hold them; an unbuilt row is not a row a finder can
    // see, and this test is about the button at the end of them.
    useTallScreen(tester);

    script = ScriptedEmployees(
      idOf: (item) => item.id,
      pageSize: 15,
      items: [for (var i = 1; i <= 20; i++) employee(id: i)],
    );

    await pumpList(tester);
    await advance(tester);

    expect(find.byKey(const ValueKey('list-load-more')), findsOneWidget);
    expect(script.listCalls, 1);
    expect(script.lastPage, 1);

    await tester.tap(find.byKey(const ValueKey('list-load-more')));
    await advance(tester);

    expect(script.listCalls, 2);
    expect(script.lastPage, 2);
    // Appended, not replaced — a second page that threw away the first would
    // make the button jump the user backwards.
    expect(find.text('Person 1'), findsOneWidget);
    expect(find.text('Person 16'), findsOneWidget);
    expect(find.byKey(const ValueKey('list-load-more')), findsNothing);
  });

  testWidgets('a session without employees.view is never asked for them',
      (tester) async {
    await tester.pumpWidget(
      scopedPhase4(
        permissions: const ['projects.view'],
        employees: script,
        child: const MaterialApp(home: EmployeesListScreen()),
      ),
    );
    await advance(tester);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
    // The create button is a promise the API would not have kept either.
    expect(find.byKey(const ValueKey('add-employee')), findsNothing);
  });

  testWidgets('the create button appears only for employees.create',
      (tester) async {
    await pumpList(tester);
    await advance(tester);

    expect(find.byKey(const ValueKey('add-employee')), findsOneWidget);
  });
}
