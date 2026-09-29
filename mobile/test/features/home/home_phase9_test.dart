import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';

import '../../support/fakes.dart';
import '../../support/phase4.dart' show useTallScreen;

/// Number of frames given to a route change to finish.
///
/// Deliberately a counted loop rather than `pumpAndSettle`: the splash screen
/// holds a spinner that never stops, so settling would run until the test
/// times out on an animation that is behaving perfectly.
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

  testWidgets('a session without expenses.view is offered no expense door', (
    tester,
  ) async {
    await pumpHome(tester, permissions: const ['attendance.view']);

    expect(find.byKey(const ValueKey('module-Expenses')), findsNothing);
    expect(find.text('Claims, receipts and approvals'), findsNothing);
  });

  testWidgets('the expense door appears under its own grant, beside the '
      'other time-off work', (tester) async {
    await pumpHome(
      tester,
      permissions: const ['expenses.view', 'overtime.view'],
    );

    expect(find.byKey(const ValueKey('module-Expenses')), findsOneWidget);
    expect(find.text('Claims, receipts and approvals'), findsOneWidget);
  });

  testWidgets('the door is withheld when the grant is a different one', (
    tester,
  ) async {
    // `expenses.create` without `expenses.view` is a real combination in the
    // seeder — a session that may file a claim but has not been granted the
    // list. The route gate would refuse the list anyway, so offering a door
    // that dead-ends would be a promise the API does not keep.
    await pumpHome(tester, permissions: const ['expenses.create']);

    expect(find.byKey(const ValueKey('module-Expenses')), findsNothing);
  });
}
