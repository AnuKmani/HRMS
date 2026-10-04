import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';
import 'package:mobile/core/router/app_router.dart';
import 'package:mobile/features/account/change_password_screen.dart';
import 'package:mobile/features/auth/auth_controller.dart';
import 'package:mobile/features/auth/auth_models.dart';
import 'package:mobile/features/home/home_screen.dart';

import 'support/fakes.dart';

Future<void> advance(WidgetTester tester, {int frames = 8}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

/// A login HR handed out lands on "Set your password" and cannot go
/// anywhere else until a new password is chosen.
void main() {
  AuthUser withTemporaryPassword() {
    final base = buildUser(name: 'New Joiner');
    return AuthUser(
      id: base.id,
      name: base.name,
      email: base.email,
      status: base.status,
      roles: base.roles,
      permissions: base.permissions,
      mustChangePassword: true,
    );
  }

  testWidgets('first sign-in goes to Set your password, and stays there', (
    tester,
  ) async {
    final tokens = InMemoryTokenStore()..value = 'stored-token';
    await tester.pumpWidget(
      scopedAuth(
        repository: FakeAuthRepository(user: withTemporaryPassword()),
        tokenStore: tokens,
        child: const HrmsApp(),
      ),
    );
    await advance(tester);

    expect(find.byType(ChangePasswordScreen), findsOneWidget);
    expect(find.text('Set your password'), findsOneWidget);
    expect(find.byKey(const ValueKey('first-login-note')), findsOneWidget);
    expect(find.byType(HomeScreen), findsNothing);

    final container = ProviderScope.containerOf(
      tester.element(find.byType(HrmsApp)),
      listen: false,
    );

    // Trying to leave is refused.
    container.read(routerProvider).go('/home');
    await advance(tester);
    expect(find.byType(ChangePasswordScreen), findsOneWidget);

    // Once the password is their own, the rest of the app opens.
    container.read(authControllerProvider.notifier).passwordChanged();
    container.read(routerProvider).go('/home');
    await advance(tester);
    expect(find.byType(HomeScreen), findsOneWidget);
  });
}
