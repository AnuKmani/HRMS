import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/app.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/router/app_router.dart';
import 'package:mobile/features/auth/auth_controller.dart';
import 'package:mobile/features/auth/auth_state.dart';
import 'package:mobile/features/auth/login_screen.dart';
import 'package:mobile/features/auth/splash_screen.dart';
import 'package:mobile/features/home/home_screen.dart';

import 'support/fakes.dart';

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
    repository = FakeAuthRepository(user: buildUser(name: 'Grace Hopper'));
    tokenStore = InMemoryTokenStore();
  });

  Future<ProviderContainer> pumpApp(WidgetTester tester) async {
    await tester.pumpWidget(
      scopedAuth(
        repository: repository,
        tokenStore: tokenStore,
        child: const HrmsApp(),
      ),
    );

    final container = ProviderScope.containerOf(
      tester.element(find.byType(HrmsApp)),
      listen: false,
    );

    // First frame renders the splash, whose post-frame callback starts the
    // restore; the frames after that carry the state change and the route
    // transition it triggers.
    await advance(tester);

    return container;
  }

  group('session restore', () {
    testWidgets('lands on the sign-in form when nothing is stored',
        (tester) async {
      await pumpApp(tester);

      expect(find.byType(SplashScreen), findsNothing);
      expect(find.byType(HomeScreen), findsNothing);
      expect(find.byType(LoginScreen), findsOneWidget);
      expect(repository.meCalls, 0, reason: 'no token, no request');
    });

    testWidgets('goes straight to the home screen with a stored token',
        (tester) async {
      tokenStore.value = 'stored-token';

      await pumpApp(tester);

      expect(find.byType(LoginScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text('Grace Hopper'), findsOneWidget);
      expect(repository.meCalls, 1);
      expect(tokenStore.value, 'stored-token');
    });

    testWidgets('discards a token the server has revoked', (tester) async {
      tokenStore.value = 'revoked-token';
      repository.meError = const ApiException(
        statusCode: 401,
        message: 'Unauthenticated.',
      );

      await pumpApp(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(tokenStore.value, isNull, reason: 'no dead token kept around');
    });

    testWidgets('keeps a token the server could not be asked about',
        (tester) async {
      tokenStore.value = 'good-token';
      repository.meError = const ApiException(
        statusCode: 0,
        message: 'Could not reach the server. Check your connection and try again.',
      );

      await pumpApp(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.textContaining('Could not reach the server'), findsOneWidget);
      expect(tokenStore.value, 'good-token');
    });
  });

  group('the guard', () {
    testWidgets('sends a signed-in user away from the sign-in form',
        (tester) async {
      tokenStore.value = 'stored-token';

      await pumpApp(tester);
      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('refuses to reach /home without a session', (tester) async {
      final container = await pumpApp(tester);
      expect(find.byType(LoginScreen), findsOneWidget);

      container.read(routerProvider).go('/home');
      await advance(tester);

      expect(find.byType(HomeScreen), findsNothing);
      expect(find.byType(LoginScreen), findsOneWidget);
    });

    testWidgets('refuses to leave /home without a session', (tester) async {
      final container = await pumpApp(tester);
      expect(find.byType(LoginScreen), findsOneWidget);

      container.read(routerProvider).go('/home');
      await advance(tester);
      container.read(routerProvider).go('/');
      await advance(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.byType(SplashScreen), findsNothing);
    });
  });

  group('signing in and out', () {
    testWidgets('a submitted form lands on the home screen', (tester) async {
      await pumpApp(tester);

      await tester.enterText(find.byType(TextFormField).at(0), 'grace@example.com');
      await tester.enterText(find.byType(TextFormField).at(1), 'secret');
      await tester.tap(find.text('Sign in'));
      await advance(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text('Grace Hopper'), findsOneWidget);
      expect(tokenStore.value, 'token-from-server');
    });

    testWidgets('signing out from the home screen returns to the form',
        (tester) async {
      tokenStore.value = 'stored-token';
      await pumpApp(tester);
      expect(find.byType(HomeScreen), findsOneWidget);

      await tester.tap(find.byTooltip('Sign out'));
      await advance(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(tokenStore.value, isNull);
      expect(repository.logoutCalls, 1);
    });

    testWidgets('a token revoked elsewhere returns the user to the form',
        (tester) async {
      tokenStore.value = 'stored-token';
      final container = await pumpApp(tester);
      expect(find.byType(HomeScreen), findsOneWidget);

      // What the HTTP layer does when any request carrying our token comes
      // back a 401 — a session ended from another device, a password change.
      container.read(authControllerProvider.notifier).signOutLocally();
      await advance(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.textContaining('Your session has ended'), findsOneWidget);
      expect(tokenStore.value, isNull);
      expect(
        container.read(authControllerProvider).status,
        AuthStatus.unauthenticated,
      );
    });
  });
}
