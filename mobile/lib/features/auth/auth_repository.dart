import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_exception.dart';
import 'auth_models.dart';

/// The three API calls this app knows how to make.
///
/// Abstract so the controller can be tested against an in-memory
/// implementation: nothing in the auth tests has to reach a socket, and the
/// widget tests can decide exactly which failure they want to see.
abstract class AuthRepository {
  /// Exchange credentials for a bearer token.
  ///
  /// [deviceName] becomes the token's display name on the server, and one
  /// token per name is kept — so signing in twice from the same app replaces
  /// this device's previous session instead of orphaning it.
  Future<LoginResult> login({
    required String email,
    required String password,
    required String deviceName,
  });

  /// Who is holding the token right now. Used to restore a session on launch.
  Future<AuthUser> me();

  /// Revoke the token presented by this request. Other devices stay signed in.
  Future<void> logout();
}

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => ApiAuthRepository(ref.watch(apiClientProvider)),
);

class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository(this._client);

  final ApiClient _client;

  @override
  Future<LoginResult> login({
    required String email,
    required String password,
    required String deviceName,
  }) async {
    final envelope = await _client.post(
      '/auth/login',
      body: <String, String>{
        'email': email,
        'password': password,
        'device_name': deviceName,
      },
    );

    final body = envelope.data;
    if (body is! Map<String, dynamic>) throw _unexpectedShape();

    final token = body['token'];
    final user = body['user'];
    if (token is! String || user is! Map<String, dynamic>) {
      throw _unexpectedShape();
    }

    final expiresAt = body['expires_at'];

    return LoginResult(
      token: token,
      tokenType: body['token_type'] is String ? body['token_type']! as String : 'Bearer',
      expiresAt: expiresAt is String ? DateTime.tryParse(expiresAt) : null,
      user: AuthUser.fromJson(user),
    );
  }

  @override
  Future<AuthUser> me() async {
    final envelope = await _client.get('/auth/me');

    final body = envelope.data;
    if (body is! Map<String, dynamic>) throw _unexpectedShape();

    return AuthUser.fromJson(body);
  }

  @override
  Future<void> logout() => _client.post('/auth/logout');

  ApiException _unexpectedShape() => const ApiException(
        statusCode: 0,
        message: 'The server sent a response this app does not understand. '
            'Please check for an app update.',
      );
}
