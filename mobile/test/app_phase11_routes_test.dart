import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';
import 'package:mobile/core/router/app_router.dart';
import 'package:mobile/features/assets/presentation/asset_detail_screen.dart';
import 'package:mobile/features/assets/presentation/asset_form_screen.dart';
import 'package:mobile/features/assets/presentation/asset_history_screen.dart';
import 'package:mobile/features/assets/presentation/asset_list_screen.dart';
import 'package:mobile/features/home/home_screen.dart';
import 'package:mobile/features/training/presentation/training_compliance_screen.dart';
import 'package:mobile/features/training/presentation/training_detail_screen.dart';
import 'package:mobile/features/training/presentation/training_enroll_screen.dart';
import 'package:mobile/features/training/presentation/training_expiry_screen.dart';
import 'package:mobile/features/training/presentation/training_list_screen.dart';
import 'package:mobile/features/training/presentation/training_program_form_screen.dart';
import 'package:mobile/features/training/presentation/training_programs_screen.dart';

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

  group('the Phase 11 routes', () {
    testWidgets('every path resolves to its own screen', (tester) async {
      final container = await pumpApp(tester);
      expect(find.byType(HomeScreen), findsOneWidget);

      const targets = <String, Type>{
        '/training': TrainingListScreen,
        '/training/new': TrainingEnrollScreen,
        '/training/expiring': TrainingExpiryScreen,
        '/training/compliance': TrainingComplianceScreen,
        '/training/programs': TrainingProgramsScreen,
        '/training/programs/new': TrainingProgramFormScreen,
        '/training/programs/7': TrainingProgramFormScreen,
        '/training/7': TrainingDetailScreen,
        '/assets': AssetListScreen,
        '/assets/new': AssetFormScreen,
        '/assets/history': AssetHistoryScreen,
        '/assets/7': AssetDetailScreen,
        '/assets/7/edit': AssetFormScreen,
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

    testWidgets('`history` is a log, not a row id', (tester) async {
      final container = await pumpApp(tester);

      // `/assets/history` sits ahead of `/assets/:id`. Were the order
      // reversed, `int.parse('history')` would throw during the build — so
      // this is the ordering rule asserted rather than described.
      container.read(routerProvider).go('/assets/history');
      await advance(tester);

      expect(find.byType(AssetHistoryScreen), findsOneWidget);
      expect(find.byType(AssetDetailScreen), findsNothing);
      expect(find.byType(AssetListScreen), findsNothing);
      expect(find.text('Hand-over log'), findsOneWidget);
    });

    testWidgets('`programs` is a catalogue, not a row id either', (
      tester,
    ) async {
      final container = await pumpApp(tester);

      container.read(routerProvider).go('/training/programs');
      await advance(tester);

      expect(find.byType(TrainingProgramsScreen), findsOneWidget);
      expect(find.byType(TrainingDetailScreen), findsNothing);
      expect(find.byType(TrainingListScreen), findsNothing);
    });

    testWidgets('`new` on both trees is not mistaken for a row id', (
      tester,
    ) async {
      final container = await pumpApp(tester);

      container.read(routerProvider).go('/training/new');
      await advance(tester);
      expect(find.byType(TrainingEnrollScreen), findsOneWidget);
      expect(find.byType(TrainingDetailScreen), findsNothing);

      container.read(routerProvider).go('/assets/new');
      await advance(tester);
      expect(find.byType(AssetFormScreen), findsOneWidget);
      expect(find.byType(AssetDetailScreen), findsNothing);
    });

    testWidgets('a session without Phase 11 grants is refused at every door, '
        'and never reaches the API', (tester) async {
      final container = await pumpApp(tester);

      // Every screen here asks `PermissionScope` before it fetches — the
      // detail screens gate their `initState` on the same condition as their
      // build — so a URL typed by hand lands on a refusal rather than on a
      // request the API would then have to answer, which is also why none of
      // these paths needs a server to be exercised.
      for (final path in const <String>[
        '/training',
        '/training/expiring',
        '/training/compliance',
        '/training/programs',
        '/training/7',
        '/assets',
        '/assets/history',
        '/assets/7',
      ]) {
        container.read(routerProvider).go(path);
        await advance(tester);

        expect(
          find.byKey(const ValueKey('no-permission')),
          findsOneWidget,
          reason: '$path should refuse rather than fetch',
        );
      }

      container.read(routerProvider).go('/training/new');
      await advance(tester);
      expect(
        find.text('You do not have permission to view training enrolment.'),
        findsOneWidget,
      );

      container.read(routerProvider).go('/assets/new');
      await advance(tester);
      expect(
        find.text('You do not have permission to view asset registration.'),
        findsOneWidget,
      );

      container.read(routerProvider).go('/assets/7/edit');
      await advance(tester);
      expect(
        find.text('You do not have permission to view asset editing.'),
        findsOneWidget,
      );

      container.read(routerProvider).go('/training/programs/new');
      await advance(tester);
      expect(
        find.text('You do not have permission to view course creation.'),
        findsOneWidget,
      );

      container.read(routerProvider).go('/training/programs/7');
      await advance(tester);
      expect(
        find.text('You do not have permission to view course editing.'),
        findsOneWidget,
      );
    });
  });
}
