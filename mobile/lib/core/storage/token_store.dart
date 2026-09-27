import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

final tokenStoreProvider = Provider<TokenStore>((ref) => SecureTokenStore());

/// Where the bearer token lives between app launches.
///
/// Abstract so tests can substitute an in-memory map. flutter_secure_storage
/// reaches into the platform keystore over a method channel, which has nothing
/// to say inside a widget test.
abstract class TokenStore {
  Future<String?> read();

  Future<void> write(String token);

  Future<void> clear();
}

/// Android Keystore / iOS Keychain backed store.
///
/// The token *is* the session: anyone holding it can act as the user until it
/// is revoked. That is why it lives here and not in SharedPreferences, a file,
/// or any other container readable without the device's lock credentials.
///
/// The value is stored under a fixed key, so signing in again overwrites the
/// previous token rather than accumulating entries.
class SecureTokenStore implements TokenStore {
  SecureTokenStore({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  static const String _key = 'hrms.auth.bearer_token';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read() => _storage.read(key: _key);

  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);

  @override
  Future<void> clear() => _storage.delete(key: _key);
}
