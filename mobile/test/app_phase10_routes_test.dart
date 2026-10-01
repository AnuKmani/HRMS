import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';
import 'package:mobile/core/router/app_router.dart';
import 'package:mobile/features/documents/presentation/document_detail_screen.dart';
import 'package:mobile/features/documents/presentation/document_expiry_screen.dart';
import 'package:mobile/features/documents/presentation/document_form_screen.dart';
import 'package:mobile/features/documents/presentation/document_list_screen.dart';
import 'package:mobile/features/home/home_screen.dart';
import 'package:mobile/features/onboarding/presentation/onboarding_detail_screen.dart';
import 'package:mobile/features/onboarding/presentation/onboarding_list_screen.dart';

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

  group('the Phase 10 routes', () {
    testWidgets('every path resolves to its own screen', (tester) async {
      final container = await pumpApp(tester);
      expect(find.byType(HomeScreen), findsOneWidget);

      const targets = <String, Type>{
        '/documents': DocumentListScreen,
        '/documents/new': DocumentFormScreen,
        '/documents/7': DocumentDetailScreen,
        '/documents/7/edit': DocumentFormScreen,
        '/onboarding': OnboardingListScreen,
        '/onboarding/7': OnboardingDetailScreen,
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

    testWidgets('`expiring` is a report, not a row id', (tester) async {
      final container = await pumpApp(tester);

      // `/documents/expiring` sits ahead of `/documents/:id`. Were the order
      // reversed, `int.parse('expiring')` would throw during the build — so
      // this is the ordering rule asserted rather than described.
      container.read(routerProvider).go('/documents/expiring');
      await advance(tester);

      expect(find.byType(DocumentExpiryScreen), findsOneWidget);
      expect(find.byType(DocumentDetailScreen), findsNothing);
      expect(find.byType(DocumentListScreen), findsNothing);
      expect(find.text('Expiring documents'), findsOneWidget);
    });

    testWidgets('`new` is not mistaken for a row id either', (tester) async {
      final container = await pumpApp(tester);

      container.read(routerProvider).go('/documents/new');
      await advance(tester);

      expect(find.byType(DocumentFormScreen), findsOneWidget);
      expect(find.byType(DocumentDetailScreen), findsNothing);
      expect(find.text('Upload document'), findsOneWidget);
    });

    testWidgets('a session without Phase 10 grants is refused at every door, '
        'and never reaches the API', (tester) async {
      final container = await pumpApp(tester);

      // Every screen here asks `PermissionScope` before it fetches — the
      // detail screens gate their `initState` on the same condition as their
      // build — so a URL typed by hand lands on a refusal rather than on a
      // request the API would then have to answer, which is also why none of
      // these paths needs a server to be exercised.
      container.read(routerProvider).go('/documents');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/documents/expiring');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/documents/7');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/documents/new');
      await advance(tester);
      expect(
        find.text('You do not have permission to view document upload.'),
        findsOneWidget,
      );

      container.read(routerProvider).go('/documents/7/edit');
      await advance(tester);
      expect(
        find.text('You do not have permission to view document editing.'),
        findsOneWidget,
      );

      container.read(routerProvider).go('/onboarding');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/onboarding/7');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);
    });
  });
}
