import 'auth_models.dart';

/// Where the sign-in flow is.
///
/// The values are few on purpose: every one of them is a distinct thing the
/// router and the UI have to treat differently, and no two of them can be
/// confused for each other.
enum AuthStatus {
  /// A token is stored and we are asking the server whether it still works.
  ///
  /// Distinct from [unauthenticated] because the honest answer is not known
  /// yet — routing somewhere else from here would bounce a returning user to
  /// the sign-in screen for the length of one request.
  restoring,

  /// No usable session. Show the sign-in form.
  unauthenticated,

  /// A sign-in attempt is in flight. The form should be disabled, not
  /// replaced — otherwise a tap that succeeds tears the form out from under
  /// the user and a tap that fails flickers.
  authenticating,

  /// Signed in. [AuthState.user] is populated.
  authenticated,

  /// An attempt was made and rejected. The form stays put with the server's
  /// explanation attached.
  failed,
}

/// Everything the app knows about the session at a given moment.
class AuthState {
  const AuthState({
    required this.status,
    this.user,
    this.message = '',
    this.errors = const <String, String>{},
    this.retryAfter,
  });

  factory AuthState.restoring() =>
      const AuthState(status: AuthStatus.restoring);

  factory AuthState.signedOut({String message = ''}) => AuthState(
        status: AuthStatus.unauthenticated,
        message: message,
      );

  final AuthStatus status;

  /// Set only in [AuthStatus.authenticated].
  final AuthUser? user;

  /// One line to show as a banner. Empty when there is nothing to say.
  ///
  /// Comes from the server's envelope, which is written to be shown to a
  /// person — see bootstrap/app.php. Never populated with a stack trace or a
  /// request payload.
  final String message;

  /// Field => message, for drawing under individual inputs. A failed
  /// credential check has none: the API answers 401 with a single banner on
  /// purpose, so the response is identical for an unknown address and a wrong
  /// password.
  final Map<String, String> errors;

  /// How long to wait before trying again, from `Retry-After` on a 429.
  final Duration? retryAfter;

  bool get isAuthenticated => status == AuthStatus.authenticated;

  /// True while a request owns the flow, whether it started by restoring a
  /// session or by submitting the form.
  bool get isBusy =>
      status == AuthStatus.restoring || status == AuthStatus.authenticating;

  /// Whether the router should send the user to the sign-in screen.
  bool get needsSignIn => !isAuthenticated;
}
