import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/auth_controller.dart';
import 'package:mobile/features/auth/auth_state.dart';

import '../../support/fakes.dart';

void main() {
  late FakeAuthRepository repository;
  late InMemoryTokenStore tokenStore;
  late ProviderContainer container;

  AuthState state() => container.read(authControllerProvider);
  AuthController controller() =>
      container.read(authControllerProvider.notifier);

  setUp(() {
    repository = FakeAuthRepository(user: buildUser());
    tokenStore = InMemoryTokenStore();
    container = authContainer(repository: repository, tokenStore: tokenStore);
  });

  tearDown(() => container.dispose());

  group('restoreSession', () {
    test('begins in the state the router keys off', () {
      expect(state().status, AuthStatus.restoring);
      expect(state().isBusy, isTrue);
      expect(state().needsSignIn, isTrue);
    });

    test('with no stored token does not bother the server', () async {
      await controller().restoreSession();

      expect(state().status, AuthStatus.unauthenticated);
      expect(repository.meCalls, 0);
      expect(state().message, isEmpty);
    });

    test('with a stored token signs the user back in', () async {
      tokenStore.value = 'stored-token';

      await controller().restoreSession();

      expect(state().status, AuthStatus.authenticated);
      expect(state().user?.email, 'ada@example.com');
      expect(state().isAuthenticated, isTrue);
      expect(tokenStore.value, 'stored-token', reason: 'still usable');
    });

    test('discards a token the server no longer accepts', () async {
      tokenStore.value = 'revoked-token';
      repository.meError = const ApiException(
        statusCode: 401,
        message: 'Unauthenticated.',
      );

      await controller().restoreSession();

      expect(state().status, AuthStatus.unauthenticated);
      expect(state().message, isEmpty, reason: 'not a failure worth reporting');
      expect(tokenStore.value, isNull, reason: 'the dead token is forgotten');
    });

    test('keeps the token when the server cannot be reached', () async {
      tokenStore.value = 'good-token';
      repository.meError = const ApiException(
        statusCode: 0,
        message: 'Could not reach the server. Check your connection and try again.',
      );

      await controller().restoreSession();

      // Landing on the form is correct; throwing the credential away would
      // turn a flaky connection into having been signed out.
      expect(state().status, AuthStatus.unauthenticated);
      expect(state().message, contains('Could not reach the server'));
      expect(tokenStore.value, 'good-token');
    });

    test('asks the server once, however many times it is called', () async {
      tokenStore.value = 'stored-token';

      await controller().restoreSession();
      await controller().restoreSession();

      expect(repository.meCalls, 1);
    });
  });

  group('login', () {
    test('a good password stores the token and reports the user', () async {
      final accepted = await controller().login(
        email: 'ada@example.com',
        password: 'correct horse battery staple',
      );

      expect(accepted, isTrue);
      expect(state().status, AuthStatus.authenticated);
      expect(state().user?.name, 'Ada Lovelace');
      expect(tokenStore.value, 'token-from-server');
      expect(repository.lastEmail, 'ada@example.com');
      expect(repository.lastPassword, 'correct horse battery staple');
      expect(repository.lastDeviceName, 'test-device');
    });

    test('a rejected password leaves the form with the server\'s message', () async {
      repository.loginError = invalidCredentials;

      final accepted = await controller().login(
        email: 'ada@example.com',
        password: 'wrong',
      );

      expect(accepted, isFalse);
      expect(state().status, AuthStatus.failed);
      expect(state().message, invalidCredentials.message);
      // One banner, no field marks: the API answers 401 identically for an
      // unknown address and a wrong password, and the form must not
      // contradict it by implying one input was the problem.
      expect(state().errors, isEmpty);
      expect(tokenStore.value, isNull);
      expect(repository.loginCalls, 1);
    });

    test('carries 422 errors field by field', () async {
      repository.loginError = const ApiException(
        statusCode: 422,
        message: 'The given data was invalid.',
        errors: {'email': 'The email field must be a valid email address.'},
      );

      await controller().login(email: 'not-an-email', password: 'whatever');

      expect(state().status, AuthStatus.failed);
      expect(state().errors, {
        'email': 'The email field must be a valid email address.',
      });
    });

    test('reports how long a throttle wants us to wait', () async {
      repository.loginError = throttled;

      await controller().login(email: 'ada@example.com', password: 'secret');

      expect(state().status, AuthStatus.failed);
      expect(state().message, contains('Too many attempts'));
      expect(state().retryAfter, const Duration(seconds: 42));
    });

    test('an escaped exception still lands in a usable state', () async {
      repository.loginError = StateError('something not anticipated');

      final accepted =
          await controller().login(email: 'ada@example.com', password: 'secret');

      expect(accepted, isFalse);
      expect(state().status, AuthStatus.failed);
      expect(state().message, 'Something went wrong. Please try again.');
      expect(tokenStore.value, isNull);
    });

    test('editing afterwards clears the explanation it superseded', () async {
      repository.loginError = invalidCredentials;
      await controller().login(email: 'ada@example.com', password: 'wrong');
      expect(state().message, isNotEmpty);

      controller().dismissFeedback();

      expect(state().status, AuthStatus.failed, reason: 'nothing succeeded');
      expect(state().message, isEmpty);
      expect(state().errors, isEmpty);
      expect(state().retryAfter, isNull);
    });

    test('dismissFeedback does nothing when there is nothing to dismiss', () async {
      await controller().login(email: 'ada@example.com', password: 'secret');

      controller().dismissFeedback();

      expect(state().status, AuthStatus.authenticated);
      expect(state().user, isNotNull);
    });
  });

  group('logout', () {
    test('revokes server-side and forgets the token here', () async {
      tokenStore.value = 'stored-token';

      await controller().logout();

      expect(repository.logoutCalls, 1);
      expect(tokenStore.value, isNull);
      expect(state().status, AuthStatus.unauthenticated);
      expect(state().user, isNull);
    });

    test('still ends the session when the server cannot be reached', () async {
      tokenStore.value = 'stored-token';
      repository.logoutError = const ApiException(
        statusCode: 0,
        message: 'Could not reach the server. Check your connection and try again.',
      );

      await controller().logout();

      // Signing out has to work offline: what matters is that this device
      // stops trusting the credential, and that happens either way.
      expect(repository.logoutCalls, 1);
      expect(tokenStore.value, isNull);
      expect(state().status, AuthStatus.unauthenticated);
    });

    test('does not call the server when there is no session to end', () async {
      await controller().logout();

      expect(repository.logoutCalls, 0);
      expect(state().status, AuthStatus.unauthenticated);
    });
  });

  group('signOutLocally', () {
    test('drops a session the server rejected', () async {
      await controller().login(email: 'ada@example.com', password: 'secret');
      expect(state().status, AuthStatus.authenticated);

      controller().signOutLocally();

      expect(state().status, AuthStatus.unauthenticated);
      expect(state().message, contains('session has ended'));
      expect(tokenStore.value, isNull);
    });

    test('does not trample the message a rejected sign-in is showing', () async {
      repository.loginError = invalidCredentials;
      await controller().login(email: 'ada@example.com', password: 'wrong');

      controller().signOutLocally();

      expect(state().status, AuthStatus.failed);
      expect(state().message, invalidCredentials.message);
    });

    test('does not announce a second sign-out to someone already at the form', () async {
      await controller().logout();

      controller().signOutLocally();

      expect(state().status, AuthStatus.unauthenticated);
      expect(state().message, isEmpty);
    });
  });
}
