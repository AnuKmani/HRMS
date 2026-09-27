import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/storage/device_identity.dart';
import 'package:mobile/core/storage/token_store.dart';
import 'package:mobile/features/auth/auth_models.dart';
import 'package:mobile/features/auth/auth_repository.dart';

/// A [TokenStore] with no platform channel behind it.
///
/// [value] is a plain field so a test can read it synchronously the instant a
/// `write`/`clear` call returns — awaiting a future only to find the store
/// still holding the old token would make every assertion about "did we
/// forget this?" a race.
class InMemoryTokenStore implements TokenStore {
  InMemoryTokenStore([this.value]);

  String? value;

  @override
  Future<String?> read() async => value;

  @override
  Future<void> write(String token) {
    value = token;
    return Future<void>.value();
  }

  @override
  Future<void> clear() {
    value = null;
    return Future<void>.value();
  }
}

/// A device label that does not need the keystore (or `dart:io`) to exist.
class FixedDeviceIdentity implements DeviceIdentity {
  const FixedDeviceIdentity(this.value);

  final String value;

  @override
  Future<String> label() async => value;
}

/// An [AuthRepository] that does exactly what the test told it to, and
/// records what it was asked — no HTTP, no timing, nothing to wait on.
///
/// Set `loginError` / `meError` / `logoutError` to make the next call of that
/// kind fail. They are cleared when consumed, so a failure is one step of a
/// scenario rather than a sticky switch the rest of the test has to remember.
class FakeAuthRepository implements AuthRepository {
  FakeAuthRepository({required this.user, this.token = 'token-from-server'});

  AuthUser user;
  String token;

  Object? loginError;
  Object? meError;
  Object? logoutError;

  int loginCalls = 0;
  int meCalls = 0;
  int logoutCalls = 0;

  String? lastEmail;
  String? lastPassword;
  String? lastDeviceName;

  @override
  Future<LoginResult> login({
    required String email,
    required String password,
    required String deviceName,
  }) async {
    loginCalls++;
    lastEmail = email;
    lastPassword = password;
    lastDeviceName = deviceName;

    final error = loginError;
    if (error != null) {
      loginError = null;
      throw error;
    }

    return LoginResult(
      token: token,
      tokenType: 'Bearer',
      expiresAt: null,
      user: user,
    );
  }

  @override
  Future<AuthUser> me() async {
    meCalls++;

    final error = meError;
    if (error != null) {
      meError = null;
      throw error;
    }

    return user;
  }

  @override
  Future<void> logout() async {
    logoutCalls++;

    final error = logoutError;
    if (error != null) {
      logoutError = null;
      throw error;
    }
  }
}

AuthUser buildUser({
  int id = 7,
  String name = 'Ada Lovelace',
  String email = 'ada@example.com',
  String status = 'active',
  List<String> roles = const ['Employee'],
  List<String> permissions = const ['attendance.view'],
  EmployeeBrief? employee,
}) => AuthUser(
  id: id,
  name: name,
  email: email,
  status: status,
  roles: roles,
  permissions: permissions,
  employee: employee,
);

/// The providers every auth test shares — a fake server, a fake keystore, and
/// a device label that cannot collide with anything — wired into a widget tree.
///
/// The override list is written out inside a `ProviderScope` literal rather
/// than returned from a helper: `Override` is a riverpod type that
/// `flutter_riverpod` deliberately does not re-export, so naming it would mean
/// importing a package this project does not declare. Letting the literal
/// infer the type costs three repeated lines and no dependency.
Widget scopedAuth({
  required FakeAuthRepository repository,
  required InMemoryTokenStore tokenStore,
  required Widget child,
  DeviceIdentity identity = const FixedDeviceIdentity('test-device'),
}) => ProviderScope(
  overrides: [
    authRepositoryProvider.overrideWithValue(repository),
    tokenStoreProvider.overrideWithValue(tokenStore),
    deviceIdentityProvider.overrideWithValue(identity),
  ],
  child: child,
);

/// The same wiring, for tests that want the providers without a widget tree.
ProviderContainer authContainer({
  required FakeAuthRepository repository,
  required InMemoryTokenStore tokenStore,
  DeviceIdentity identity = const FixedDeviceIdentity('test-device'),
}) => ProviderContainer(
  overrides: [
    authRepositoryProvider.overrideWithValue(repository),
    tokenStoreProvider.overrideWithValue(tokenStore),
    deviceIdentityProvider.overrideWithValue(identity),
  ],
);

const ApiException invalidCredentials = ApiException(
  statusCode: 401,
  message: 'The email or password you entered is incorrect.',
);

const ApiException throttled = ApiException(
  statusCode: 429,
  message: 'Too many attempts. Please wait a moment and try again.',
  retryAfter: Duration(seconds: 42),
);
