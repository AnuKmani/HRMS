import 'dart:io';
import 'dart:math';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

final deviceIdentityProvider = Provider<DeviceIdentity>(
  (ref) => SecureDeviceIdentity(),
);

/// A stable, opaque label for this installation.
///
/// Sent as `device_name` on login, which the server stores as the token's
/// display name and matches on when it replaces "this device's" previous
/// token.
///
/// Both halves of that matter:
///
///  * *stable across launches of the same install* — otherwise every sign-in
///    would orphan the previous token, which nobody can see or revoke any
///    more, and the sessions list would fill with entries nobody can remove;
///  * *different between installs* — otherwise a second handset signing in
///    would match the first handset's token by name and sign it out.
///
/// Nothing the OS offers satisfies both: two identical handsets share a model
/// and an OS version, so those collide. A random id written once to the
/// keystore does not, which is the whole reason it is generated rather than
/// derived.
abstract class DeviceIdentity {
  Future<String> label();
}

/// Keystore-backed [DeviceIdentity].
class SecureDeviceIdentity implements DeviceIdentity {
  SecureDeviceIdentity({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const String _key = 'hrms.install.id';

  final FlutterSecureStorage _storage;

  @override
  Future<String> label() async {
    final existing = await _storage.read(key: _key);
    if (existing != null && existing.isNotEmpty) return _format(existing);

    final random = Random.secure();
    final id = List<int>.generate(8, (_) => random.nextInt(256))
        .map((byte) => byte.toRadixString(16).padLeft(2, '0'))
        .join();

    await _storage.write(key: _key, value: id);

    return _format(id);
  }

  /// Readable in the server's sessions list ("android 9f3c1a2b7d4e5f60")
  /// without either half carrying anything about the account.
  String _format(String id) => '${Platform.operatingSystem} $id';
}
