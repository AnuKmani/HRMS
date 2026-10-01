import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';

import '../../support/fakes.dart';
import '../../support/phase4.dart' show useTallScreen;

/// Number of frames given to a route change to finish.
///
/// Counted rather than `pumpAndSettle`: the splash screen holds a spinner
/// that never stops, so settling would run until the test timed out on an
/// animation that is behaving perfectly.
Future<void> advance(WidgetTester tester, {int frames = 8}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  late FakeAuthRepository repository;
  late InMemoryTokenStore tokenStore;

  setUp(() {
    tokenStore = InMemoryTokenStore('stored-token');
  });

  Future<void> pumpHome(
    WidgetTester tester, {
    List<String> permissions = const [],
  }) async {
    useTallScreen(tester);

    repository = FakeAuthRepository(user: buildUser(permissions: permissions));

    await tester.pumpWidget(
      scopedAuth(
        repository: repository,
        tokenStore: tokenStore,
        child: const HrmsApp(),
      ),
    );

    // Through the app rather than pumping `HomeScreen` on its own: the tiles
    // read `PermissionScope`, which is empty until the session has been
    // restored, and a screen pumped directly is never restored.
    await advance(tester);
    expect(find.text('HRMS'), findsOneWidget);
  }

  // One session per test rather than two: re-pumping `HrmsApp` inside a test
  // re-runs the bootstrap it just finished, and asserting on *that* would be
  // asserting on a race with the splash screen instead of on the doors.
  testWidgets('a session with neither grant is offered neither door', (
    tester,
  ) async {
    await pumpHome(tester, permissions: const ['attendance.view']);

    expect(find.byKey(const ValueKey('module-Documents')), findsNothing);
    expect(find.byKey(const ValueKey('module-Onboarding')), findsNothing);
    expect(find.text('Passports, IDs, visas and contracts'), findsNothing);
    expect(find.text('Where new joiners stand'), findsNothing);
  });

  testWidgets('the documents door belongs to documents.view on its own', (
    tester,
  ) async {
    await pumpHome(tester, permissions: const ['documents.view']);

    expect(find.byKey(const ValueKey('module-Documents')), findsOneWidget);
    expect(find.text('Passports, IDs, visas and contracts'), findsOneWidget);
    // An employee's own file is not a directory of who is joining.
    expect(find.byKey(const ValueKey('module-Onboarding')), findsNothing);
  });

  testWidgets('the onboarding door belongs to onboarding.view on its own', (
    tester,
  ) async {
    // The seeder grants `documents.view` to roles that have no say over
    // joiners, and the reverse: Finance may watch a directory and may not
    // open a passport. One tile behind the other would say something untrue
    // about which question the reader was asking.
    await pumpHome(tester, permissions: const ['onboarding.view']);

    expect(find.byKey(const ValueKey('module-Onboarding')), findsOneWidget);
    expect(find.text('Where new joiners stand'), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Documents')), findsNothing);
  });

  testWidgets('both grants offer both doors, side by side', (tester) async {
    await pumpHome(
      tester,
      permissions: const <String>['documents.view', 'onboarding.view'],
    );

    expect(find.byKey(const ValueKey('module-Documents')), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Onboarding')), findsOneWidget);
  });

  testWidgets('the expiry report is not a door of its own', (tester) async {
    // `documents.expiry.view` is a report *inside* the documents screen.
    // Holding it alone must not open one: the question it answers is "what
    // is about to lapse among the people whose file I already may read".
    await pumpHome(tester, permissions: const ['documents.expiry.view']);

    expect(find.byKey(const ValueKey('module-Documents')), findsNothing);
    expect(find.byKey(const ValueKey('module-Onboarding')), findsNothing);
  });

  testWidgets('reading the expiry report still needs the door it sits '
      'behind', (tester) async {
    await pumpHome(
      tester,
      permissions: const ['documents.view', 'documents.expiry.view'],
    );

    expect(find.byKey(const ValueKey('module-Documents')), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Onboarding')), findsNothing);
  });
}
