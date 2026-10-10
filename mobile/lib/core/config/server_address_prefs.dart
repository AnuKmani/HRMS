import 'package:shared_preferences/shared_preferences.dart';

import 'server_address.dart';

/// The tester's chosen server address, kept on the phone between launches.
class PrefsServerAddressStore implements ServerAddressStore {
  PrefsServerAddressStore(this._preferences);

  static const _key = 'hrms.server_address';

  final SharedPreferences _preferences;

  @override
  String? read() => _preferences.getString(_key);

  @override
  Future<void> write(String? url) async {
    if (url == null) {
      await _preferences.remove(_key);
    } else {
      await _preferences.setString(_key, url);
    }
  }
}
