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

    expect(find.byKey(const ValueKey('module-Training')), findsNothing);
    expect(find.byKey(const ValueKey('module-Assets')), findsNothing);
    expect(find.text('Courses, certificates and expiry'), findsNothing);
    expect(find.text('Company kit and who is holding it'), findsNothing);
  });

  testWidgets('the training door belongs to training.view on its own', (
    tester,
  ) async {
    await pumpHome(tester, permissions: const ['training.view']);

    expect(find.byKey(const ValueKey('module-Training')), findsOneWidget);
    expect(find.text('Courses, certificates and expiry'), findsOneWidget);
    // An asset register is not a course history.
    expect(find.byKey(const ValueKey('module-Assets')), findsNothing);
  });

  testWidgets('the assets door belongs to assets.view on its own', (
    tester,
  ) async {
    // The seeders grant `training.view` to roles that have no say over the
    // register, and the reverse: a Site Engineer watches what kit they hold
    // and is not reading a course history. One tile behind the other would
    // say something untrue about which question the reader was asking.
    await pumpHome(tester, permissions: const ['assets.view']);

    expect(find.byKey(const ValueKey('module-Assets')), findsOneWidget);
    expect(find.text('Company kit and who is holding it'), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Training')), findsNothing);
  });

  testWidgets('both grants offer both doors, side by side', (tester) async {
    await pumpHome(
      tester,
      permissions: const <String>['training.view', 'assets.view'],
    );

    expect(find.byKey(const ValueKey('module-Training')), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Assets')), findsOneWidget);
  });

  testWidgets('the expiry report and the hand-over log are not doors of their '
      'own', (tester) async {
    // `training.expiry.view` is a report *inside* the training screen and
    // `assets.history.view` is a log *inside* the register. Holding either
    // alone must not open one: the questions they answer are about people
    // whose record the reader already may read.
    await pumpHome(
      tester,
      permissions: const <String>[
        'training.expiry.view',
        'assets.history.view',
      ],
    );

    expect(find.byKey(const ValueKey('module-Training')), findsNothing);
    expect(find.byKey(const ValueKey('module-Assets')), findsNothing);
  });

  testWidgets('the enrol door is not a door of its own either', (tester) async {
    // `training.assign` is what lets a person *put somebody on* a course.
    // It is not the right to read the list of courses, so it opens no tile —
    // the same way `documents.create` does not open the documents tile.
    await pumpHome(tester, permissions: const ['training.assign']);

    expect(find.byKey(const ValueKey('module-Training')), findsNothing);
    expect(find.byKey(const ValueKey('module-Assets')), findsNothing);
  });
}
