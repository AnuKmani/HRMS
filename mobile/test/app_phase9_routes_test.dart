import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';
import 'package:mobile/core/router/app_router.dart';
import 'package:mobile/features/expenses/presentation/expense_detail_screen.dart';
import 'package:mobile/features/expenses/presentation/expense_form_screen.dart';
import 'package:mobile/features/expenses/presentation/expense_list_screen.dart';
import 'package:mobile/features/home/home_screen.dart';

import 'support/fakes.dart';

/// Number of frames given to a route change to finish — a counted loop
/// rather than `pumpAndSettle`, because several of these screens hold a
/// spinner that has nothing to settle on.
Future<void> advance(WidgetTester tester, {int frames = 8}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  late FakeAuthRepository repository;
  late InMemoryTokenStore tokenStore;

  setUp(() {
    repository = FakeAuthRepository(user: buildUser());
    tokenStore = InMemoryTokenStore('stored-token');
  });

  Future<ProviderContainer> pumpApp(WidgetTester tester) async {
    await tester.pumpWidget(
      scopedAuth(
        repository: repository,
        tokenStore: tokenStore,
        child: const HrmsApp(),
      ),
    );
    await advance(tester);

    return ProviderScope.containerOf(
      tester.element(find.byType(HrmsApp)),
      listen: false,
    );
  }

  group('the Phase 9 routes', () {
    testWidgets('every path resolves to its own screen', (tester) async {
      final container = await pumpApp(tester);
      expect(find.byType(HomeScreen), findsOneWidget);

      const targets = <String, Type>{
        '/expenses': ExpenseListScreen,
        '/expenses/new': ExpenseFormScreen,
        '/expenses/7': ExpenseDetailScreen,
        '/expenses/7/edit': ExpenseFormScreen,
      };

      for (final entry in targets.entries) {
        container.read(routerProvider).go(entry.key);
        await advance(tester);

        expect(
          find.byType(entry.value),
          findsOneWidget,
          reason: '${entry.key} should land on ${entry.value}',
        );
      }
    });

    testWidgets('a session without expenses grants is refused at every door, '
        'and never reaches the API', (tester) async {
      final container = await pumpApp(tester);

      // Every Phase 9 screen asks `PermissionScope` before it fetches — the
      // form gates its `initState` on the same condition as its build — so a
      // URL typed by hand lands on a refusal rather than on a request the API
      // would then have to answer, which is also why none of these paths
      // needs a server to be exercised.
      container.read(routerProvider).go('/expenses');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/expenses/7');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/expenses/new');
      await advance(tester);
      expect(find.text('You may not raise an expense claim.'), findsOneWidget);

      container.read(routerProvider).go('/expenses/7/edit');
      await advance(tester);
      expect(find.text('You may not edit this draft.'), findsOneWidget);
    });

    testWidgets('`new` is not mistaken for a row id', (tester) async {
      final container = await pumpApp(tester);

      // `/expenses/new` sits ahead of `/expenses/:id`. Were the order
      // reversed, `int.parse('new')` would throw during the build — so this
      // is the ordering rule asserted rather than described.
      container.read(routerProvider).go('/expenses/new');
      await advance(tester);

      expect(find.byType(ExpenseListScreen), findsNothing);
      expect(find.byType(ExpenseDetailScreen), findsNothing);
      expect(find.byType(ExpenseFormScreen), findsOneWidget);
      expect(find.text('New expense claim'), findsOneWidget);
    });
  });
}
