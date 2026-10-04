import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_exception.dart';

final accountRepositoryProvider = Provider<AccountRepository>(
  (ref) => ApiAccountRepository(ref.watch(apiClientProvider)),
);

/// Your own account: password and signed-in devices.
///
/// Completing a reset — `POST /auth/reset-password` — is deliberately not
/// here: it is
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

  /// Every device currently signed in to this account, newest first.
  Future<List<DeviceSession>> sessions();

  /// Ends one other device's session. Returns the server's sentence.
  Future<String> revokeSession(int id);
}

/// One signed-in device — a Sanctum token, described without its secret.
class DeviceSession {
  const DeviceSession({
    required this.id,
    required this.device,
    required this.isCurrent,
    this.lastUsedAt,
    this.createdAt,
  });

  final int id;
  final String device;

  /// The session this request was made on — the phone in your hand.
  final bool isCurrent;

  final DateTime? lastUsedAt;
  final DateTime? createdAt;

  factory DeviceSession.fromJson(Map<String, dynamic> json) => DeviceSession(
    id: json['id'] as int,
    device: json['device'] as String? ?? 'Unknown device',
    isCurrent: json['is_current'] == true,
    lastUsedAt: DateTime.tryParse(json['last_used_at'] as String? ?? ''),
    createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
  );
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

  @override
  Future<List<DeviceSession>> sessions() async {
    final data = (await _client.get('/auth/sessions')).data;
    if (data is! List) throw unexpectedShapeException;

    return [
      for (final row in data)
        if (row is Map<String, dynamic>) DeviceSession.fromJson(row),
    ];
  }

  @override
  Future<String> revokeSession(int id) async =>
      (await _client.delete('/auth/sessions/$id')).message;
}
