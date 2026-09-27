import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_exception.dart';
import '../../core/storage/device_identity.dart';
import '../../core/storage/token_store.dart';
import 'auth_repository.dart';
import 'auth_state.dart';

final authControllerProvider = NotifierProvider<AuthController, AuthState>(
  AuthController.new,
);

/// Owns the session: restoring it at launch, exchanging credentials for one,
/// ending it, and reacting to the server saying one has stopped being valid.
///
/// Every transition writes a whole new [AuthState]. There is no partially
/// updated state to reason about, and no way for `user` to belong to one
/// status while `status` says another.
class AuthController extends Notifier<AuthState> {
  late AuthRepository _repository;
  late TokenStore _tokenStore;
  late DeviceIdentity _deviceIdentity;

  StreamSubscription<void>? _sessionSubscription;
  bool _restoreAttempted = false;

  @override
  AuthState build() {
    _repository = ref.watch(authRepositoryProvider);
    _tokenStore = ref.watch(tokenStoreProvider);
    _deviceIdentity = ref.watch(deviceIdentityProvider);

    _sessionSubscription = ref
        .watch(apiClientProvider)
        .sessionRejected
        .listen((_) => signOutLocally());

    ref.onDispose(() {
      _sessionSubscription?.cancel();
      _sessionSubscription = null;
    });

    return AuthState.restoring();
  }

  /// Ask the server whether the stored token still works. Once per app run.
  ///
  /// A definite "no" (401) discards the token and lands on the sign-in form.
  /// Anything else that is not a definite no — an offline device, a 500 —
  /// keeps the token and says so, because the credential may well be fine and
  /// throwing it away would make an unreachable network look like being
  /// signed out.
  Future<void> restoreSession() async {
    if (_restoreAttempted) return;
    _restoreAttempted = true;

    final token = await _tokenStore.read();

    if (token == null || token.isEmpty) {
      state = AuthState.signedOut();
      return;
    }

    try {
      final user = await _repository.me();
      state = AuthState(status: AuthStatus.authenticated, user: user);
    } on ApiException catch (failure) {
      if (failure.isUnauthenticated) {
        await _tokenStore.clear();
        state = AuthState.signedOut();
        return;
      }

      state = AuthState.signedOut(message: failure.message);
    } catch (_) {
      state = AuthState.signedOut(
        message:
            'We could not reach the server to restore your session. '
            'Check your connection and sign in again.',
      );
    }
  }

  /// Exchange credentials for a session. Returns whether it succeeded.
  ///
  /// Never throws: this is called from a button, and an unhandled exception
  /// there would leave the form spinning forever with no way to find out why.
  /// Every failure lands in [state] where the form can draw it.
  Future<bool> login({required String email, required String password}) async {
    state = const AuthState(status: AuthStatus.authenticating);

    try {
      final result = await _repository.login(
        email: email,
        password: password,
        deviceName: await _deviceIdentity.label(),
      );

      // Written before the state changes, so there is no moment where the UI
      // says "signed in" but a relaunch would find no token.
      await _tokenStore.write(result.token);

      state = AuthState(status: AuthStatus.authenticated, user: result.user);
      return true;
    } on ApiException catch (failure) {
      state = AuthState(
        status: AuthStatus.failed,
        message: failure.message,
        errors: failure.errors,
        retryAfter: failure.retryAfter,
      );
      return false;
    } catch (_) {
      state = const AuthState(
        status: AuthStatus.failed,
        message: 'Something went wrong. Please try again.',
      );
      return false;
    }
  }

  /// End this device's session: revoke it server-side, then forget it here.
  Future<void> logout() async {
    final hasToken = (await _tokenStore.read())?.isNotEmpty ?? false;

    if (hasToken) {
      try {
        await _repository.logout();
      } on ApiException {
        // Deliberately swallowed. Signing out has to work offline: the point
        // is that this device stops trusting a credential, and clearing it
        // below does exactly that. The server-side copy then stays valid
        // until it expires — recorded in docs/SECURITY.md rather than
        // hidden behind a retry queue the user did not ask for.
      }
    }

    await _tokenStore.clear();
    state = AuthState.signedOut();
  }

  /// The server rejected a token this app sent. Drop it without asking.
  ///
  /// Reached from the HTTP layer when any request carrying our token gets a
  /// 401 — the token was revoked from another device, the password changed,
  /// or it aged out.
  void signOutLocally() {
    // A rejected *sign-in attempt* attaches no token, so this only ever fires
    // for a stored session. The guards keep it from clobbering a message the
    // user is still reading, and from announcing a second sign-out to someone
    // who is already at the form.
    if (state.status == AuthStatus.failed ||
        state.status == AuthStatus.unauthenticated) {
      return;
    }

    unawaited(_tokenStore.clear());

    state = AuthState.signedOut(
      message: 'Your session has ended. Please sign in again.',
    );
  }

  /// Clear the previous attempt's explanation once the user starts editing.
  ///
  /// Without this, "that address is not valid" would keep sitting under the
  /// field it was about after the input it described had already changed —
  /// and the banner would outlive the failure it reported.
  void dismissFeedback() {
    if (state.status != AuthStatus.failed) return;

    if (state.message.isEmpty &&
        state.errors.isEmpty &&
        state.retryAfter == null) {
      return;
    }

    // Stays in `failed` on purpose: nothing has succeeded, and the status
    // badge should not flicker back to "signed out" mid-edit.
    state = const AuthState(status: AuthStatus.failed);
  }
}
