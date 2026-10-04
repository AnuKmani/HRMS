import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';

final accountRepositoryProvider = Provider<AccountRepository>(
  (ref) => ApiAccountRepository(ref.watch(apiClientProvider)),
);

/// The two password endpoints the app drives itself.
///
/// The third — `POST /auth/reset-password` — is deliberately not here: it is
/// completed on the web page the reset email links to, because the token
/// travels in that email and nowhere else.
abstract class AccountRepository {
  /// Returns the server's confirmation sentence.
  Future<String> changePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  });

  /// Always answers the same sentence whether or not the address exists —
  /// the endpoint is built so it cannot be used to discover accounts.
  Future<String> requestPasswordReset(String email);
}

class ApiAccountRepository implements AccountRepository {
  ApiAccountRepository(this._client);

  final ApiClient _client;

  @override
  Future<String> changePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  }) async {
    final envelope = await _client.post(
      '/auth/change-password',
      body: <String, String>{
        'current_password': currentPassword,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
    );

    return envelope.message;
  }

  @override
  Future<String> requestPasswordReset(String email) async {
    final envelope = await _client.post(
      '/auth/forgot-password',
      body: <String, String>{'email': email},
    );

    return envelope.message;
  }
}
