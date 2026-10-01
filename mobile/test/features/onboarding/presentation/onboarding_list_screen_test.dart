import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/features/onboarding/domain/onboarding.dart';
import 'package:mobile/features/onboarding/presentation/onboarding_list_screen.dart';

import '../../../support/phase10.dart';
import '../../../support/site_reports.dart' show tapIn;

void main() {
  late ScriptedOnboarding script;

  setUp(() {
    script = ScriptedOnboarding.rows([
      onboardingRow(
        employeeId: 4,
        name: 'Anu Kmani',
        code: 'EMP-0004',
        status: Onboarding.statusDraft,
        exists: false,
      ),
      onboardingRow(
        employeeId: 5,
        name: 'Meera Nair',
        code: 'EMP-0005',
        status: Onboarding.statusPendingDocuments,
        total: 8,
        satisfied: 3,
        missing: const <String>['passport', 'visa'],
      ),
      onboardingRow(
        employeeId: 6,
        name: 'Ravi Das',
        code: 'EMP-0006',
        status: Onboarding.statusCompleted,
        completedAt: '2026-09-30T09:00:00Z',
      ),
    ]);
  });

  Future<void> pumpList(
    WidgetTester tester, {
    List<String> permissions = const ['onboarding.view'],
  }) async {
    useTallScreen(tester);

    await tester.pumpWidget(
      scopedPhase10(
        permissions: permissions,
        onboarding: script,
        child: const MaterialApp(home: OnboardingListScreen()),
      ),
    );
    await advance(tester);
  }

  testWidgets('a session without onboarding.view is never asked for a '
      'directory', (tester) async {
    await pumpList(tester, permissions: const []);

    expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    expect(script.listCalls, 0);
  });

  testWidgets('every joiner appears, including the one nobody has begun', (
    tester,
  ) async {
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);

    // A directory, not a table of records: an absent row would read as
    // "nothing to onboard" rather than "nobody has started".
    expect(find.byKey(const ValueKey('onboarding-row-4')), findsOneWidget);
    expect(find.byKey(const ValueKey('onboarding-row-5')), findsOneWidget);
    expect(find.byKey(const ValueKey('onboarding-row-6')), findsOneWidget);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('onboarding-row-4')),
        matching: find.text('Not started'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('onboarding-row-5')),
        // One subtitle, joined — and it carries the code, the stage *and*
        // the count, so a directory answers "where does this person stand?"
        // without a tap.
        matching: find.text(
          'EMP-0005 · Waiting on documents · 3 of 8 requirements met',
        ),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('onboarding-row-6')),
        matching: find.text('Completed'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the stage filter is a query parameter, not a client-side '
      'slice', (tester) async {
    await pumpList(tester);

    expect(script.lastQuery!['status'], isNull);

    await tapIn(tester, find.byKey(const ValueKey('onboarding-status-filter')));
    await tester.pumpAndSettle();

    // Scoped to the menu: three rows behind it carry the same words.
    await tester.tap(
      find.widgetWithText(DropdownMenuItem<String>, 'HR review'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['status'], 'hr_review');
  });

  testWidgets('the incomplete-only switch is a query parameter too', (
    tester,
  ) async {
    await pumpList(tester);

    await tapIn(
      tester,
      find.byKey(const ValueKey('onboarding-incomplete-filter')),
    );
    await tester.pumpAndSettle();

    expect(script.lastQuery!['incomplete'], '1');

    await tapIn(
      tester,
      find.byKey(const ValueKey('onboarding-incomplete-filter')),
    );
    await tester.pumpAndSettle();

    // Cleared rather than sent as `false`: "not filtering" and "filtering on
    // false" are different questions to the server.
    expect(script.lastQuery!.containsKey('incomplete'), isFalse);
  });

  testWidgets('an empty directory says what would appear here', (tester) async {
    script = ScriptedOnboarding();
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-empty')), findsOneWidget);
    expect(find.text('Nobody is onboarding.'), findsOneWidget);
  });

  testWidgets('while the first page is in flight there is a spinner', (
    tester,
  ) async {
    final held = Completer<void>();
    script.holdList = held;

    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-loading')), findsOneWidget);

    held.complete();
    await advance(tester);

    expect(find.byKey(const ValueKey('list-results')), findsOneWidget);
    script.holdList = null;
  });

  testWidgets('a failure on first load is a message, not a blank page', (
    tester,
  ) async {
    script.listError = unreachable;
    await pumpList(tester);

    expect(find.byKey(const ValueKey('list-error')), findsOneWidget);
    expect(find.text(unreachable.message), findsOneWidget);
  });
}
