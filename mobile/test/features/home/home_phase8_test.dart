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

  testWidgets('a session with no payroll grant is offered no pay doors', (
    tester,
  ) async {
    await pumpHome(tester, permissions: const ['attendance.view']);

    expect(find.byKey(const ValueKey('module-Payroll')), findsNothing);
    expect(find.byKey(const ValueKey('module-Salary slips')), findsNothing);
    expect(find.byKey(const ValueKey('module-Loans')), findsNothing);
    expect(
      find.byKey(const ValueKey('module-Salary certificates')),
      findsNothing,
    );
  });

  testWidgets('the four Phase 8 doors appear under their own grants', (
    tester,
  ) async {
    await pumpHome(
      tester,
      permissions: const [
        'payroll.view',
        'salary_slips.view',
        'loans.view',
        'salary_certificates.view',
      ],
    );

    expect(find.byKey(const ValueKey('module-Payroll')), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Salary slips')), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Loans')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('module-Salary certificates')),
      findsOneWidget,
    );
  });

  testWidgets('a total-only reader is offered the summary door, not the '
      'ledger', (tester) async {
    // `payroll.summary.view` alone is Management's grant: it may open the
    // payroll screen for the aggregates and must not be shown the slips
    // door, which is a different permission.
    await pumpHome(tester, permissions: const ['payroll.summary.view']);

    expect(find.byKey(const ValueKey('module-Payroll')), findsOneWidget);
    expect(find.byKey(const ValueKey('module-Salary slips')), findsNothing);
  });
}
