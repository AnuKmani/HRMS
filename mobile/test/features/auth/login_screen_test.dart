import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/auth_controller.dart';
import 'package:mobile/features/auth/auth_state.dart';
import 'package:mobile/features/auth/login_screen.dart';

import '../../support/fakes.dart';

void main() {
  late FakeAuthRepository repository;
  late InMemoryTokenStore tokenStore;

  setUp(() {
    repository = FakeAuthRepository(user: buildUser());
    tokenStore = InMemoryTokenStore();
  });

  /// Renders the form on its own, then completes the step the real app
  /// completes first.
  ///
  /// The form disables itself while a session is being restored — correct,
  /// because the router would never be showing it at that moment — so a bare
  /// widget test has to finish that restore the way the splash screen would,
  /// or every assertion would be made against a disabled button.
  Future<ProviderContainer> pumpLogin(WidgetTester tester) async {
    await tester.pumpWidget(
      scopedAuth(
        repository: repository,
        tokenStore: tokenStore,
        child: const MaterialApp(home: LoginScreen()),
      ),
    );

    final container = ProviderScope.containerOf(
      tester.element(find.byType(LoginScreen)),
      listen: false,
    );

    await container
        .read(authControllerProvider.notifier)
        .restoreSession();
    await tester.pump();

    return container;
  }

  Future<void> type(WidgetTester tester,
      {required String email, required String password}) async {
    await tester.enterText(find.byType(TextFormField).at(0), email);
    await tester.enterText(find.byType(TextFormField).at(1), password);
  }

  Future<void> tapSignIn(WidgetTester tester) async {
    await tester.tap(find.text('Sign in'));
    await tester.pump();
    await tester.pump();
  }

  testWidgets('an empty form says what is missing and sends nothing',
      (tester) async {
    await pumpLogin(tester);

    await tapSignIn(tester);

    expect(find.text('Enter your email address.'), findsOneWidget);
    expect(find.text('Enter your password.'), findsOneWidget);
    expect(repository.loginCalls, 0);
  });

  testWidgets('the typed credentials are what reaches the API',
      (tester) async {
    final container = await pumpLogin(tester);

    await type(
      tester,
      email: '  ada@example.com  ',
      password: 'correct horse battery staple',
    );
    await tapSignIn(tester);

    expect(repository.loginCalls, 1);
    // Trimmed: a pasted address with a trailing newline is still that
    // address, and the server would otherwise reject it as malformed.
    expect(repository.lastEmail, 'ada@example.com');
    expect(repository.lastPassword, 'correct horse battery staple');
    expect(repository.lastDeviceName, 'test-device');

    final state = container.read(authControllerProvider);
    expect(state.status, AuthStatus.authenticated);
    expect(state.user?.name, 'Ada Lovelace');
  });

  testWidgets('a rejected password is one banner, with no field marked',
      (tester) async {
    repository.loginError = invalidCredentials;
    await pumpLogin(tester);

    await type(tester, email: 'ada@example.com', password: 'wrong');
    await tapSignIn(tester);

    expect(
      find.text('The email or password you entered is incorrect.'),
      findsOneWidget,
    );
    // No underline of blame under either input: the API answers 401 the same
    // way for an unknown address and a wrong password, and the form must not
    // hint otherwise by singling one out.
    expect(find.text('Enter your email address.'), findsNothing);
    expect(find.text('Enter your password.'), findsNothing);
    expect(repository.loginCalls, 1);
  });

  testWidgets('a throttle says how long it wants us to wait', (tester) async {
    repository.loginError = throttled;
    await pumpLogin(tester);

    await type(tester, email: 'ada@example.com', password: 'secret');
    await tapSignIn(tester);

    expect(find.textContaining('Too many attempts'), findsOneWidget);
    expect(find.textContaining('You can try again in 42s'), findsOneWidget);
  });

  testWidgets('a 422 marks the fields the server named', (tester) async {
    repository.loginError = const ApiException(
      statusCode: 422,
      message: 'The given data was invalid.',
      errors: {'email': 'The email field must be a valid email address.'},
    );
    await pumpLogin(tester);

    // Locally plausible but not RFC-valid, so the request really reaches the
    // API — it is the server's verdict, not the form's, that has to be drawn.
    await type(tester, email: 'team@example', password: 'secret');
    await tapSignIn(tester);

    expect(repository.loginCalls, 1);
    expect(
      find.text('The email field must be a valid email address.'),
      findsOneWidget,
    );
    expect(find.text('The given data was invalid.'), findsOneWidget);
  });

  testWidgets('editing clears the message it superseded', (tester) async {
    repository.loginError = invalidCredentials;
    await pumpLogin(tester);

    await type(tester, email: 'ada@example.com', password: 'wrong');
    await tapSignIn(tester);
    expect(find.textContaining('The email or password'), findsOneWidget);

    await tester.enterText(find.byType(TextFormField).at(1), 'fixed');
    await tester.pump();

    expect(find.textContaining('The email or password'), findsNothing);
  });

  testWidgets('the password is masked until asked for', (tester) async {
    await pumpLogin(tester);

    // TextFormField does not carry `obscureText` as a field — it forwards the
    // parameter to the TextField it builds — so the flag is read where it
    // actually lives.
    final before = tester.widget<TextField>(find.byType(TextField).at(1));
    expect(before.obscureText, isTrue);

    await tester.tap(find.byTooltip('Show password'));
    await tester.pump();

    final after = tester.widget<TextField>(find.byType(TextField).at(1));
    expect(after.obscureText, isFalse);
    expect(find.byTooltip('Hide password'), findsOneWidget);
  });
}
