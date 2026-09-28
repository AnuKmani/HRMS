import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';
import 'package:mobile/core/router/app_router.dart';
import 'package:mobile/features/home/home_screen.dart';
import 'package:mobile/features/loans/presentation/loan_detail_screen.dart';
import 'package:mobile/features/loans/presentation/loan_form_screen.dart';
import 'package:mobile/features/loans/presentation/loan_list_screen.dart';
import 'package:mobile/features/payroll/presentation/payroll_detail_screen.dart';
import 'package:mobile/features/payroll/presentation/payroll_list_screen.dart';
import 'package:mobile/features/payroll/presentation/salary_slips_screen.dart';
import 'package:mobile/features/salary_certificates/presentation/salary_certificate_detail_screen.dart';
import 'package:mobile/features/salary_certificates/presentation/salary_certificate_form_screen.dart';
import 'package:mobile/features/salary_certificates/presentation/salary_certificate_list_screen.dart';

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

  group('the Phase 8 routes', () {
    testWidgets('every path resolves to its own screen', (tester) async {
      final container = await pumpApp(tester);
      expect(find.byType(HomeScreen), findsOneWidget);

      const targets = <String, Type>{
        '/payroll': PayrollListScreen,
        '/payroll/1': PayrollDetailScreen,
        '/salary-slips': SalarySlipsScreen,
        '/loans': LoanListScreen,
        '/loans/new': LoanFormScreen,
        '/loans/7': LoanDetailScreen,
        '/loans/7/edit': LoanFormScreen,
        '/salary-certificates': SalaryCertificateListScreen,
        '/salary-certificates/new': SalaryCertificateFormScreen,
        '/salary-certificates/7': SalaryCertificateDetailScreen,
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

    testWidgets('a session with no pay grant is refused at every door, and '
        'never reaches the API', (tester) async {
      final container = await pumpApp(tester);

      // Every Phase 8 screen asks `PermissionScope` first, so a URL typed by
      // hand lands on a refusal rather than on a request the API would then
      // have to answer — which is also why none of these paths needs a
      // server to be exercised.
      container.read(routerProvider).go('/payroll');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/loans/7');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/salary-certificates/7');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/salary-slips');
      await advance(tester);
      expect(find.byKey(const ValueKey('no-permission')), findsOneWidget);

      container.read(routerProvider).go('/loans/new');
      await advance(tester);
      expect(find.text('You may not ask for a loan.'), findsOneWidget);

      container.read(routerProvider).go('/salary-certificates/new');
      await advance(tester);
      expect(find.text('You may not ask for a certificate.'), findsOneWidget);
    });

    testWidgets('`new` is not mistaken for a row id', (tester) async {
      final container = await pumpApp(tester);

      // `/loans/new` sits ahead of `/loans/:id`. Were the order reversed,
      // `int.parse('new')` would throw during the build — so this is the
      // ordering rule asserted rather than described.
      container.read(routerProvider).go('/loans/new');
      await advance(tester);

      expect(find.byType(LoanListScreen), findsNothing);
      expect(find.byType(LoanDetailScreen), findsNothing);
      expect(find.byType(LoanFormScreen), findsOneWidget);
      expect(find.text('New loan'), findsOneWidget);
    });
  });
}
