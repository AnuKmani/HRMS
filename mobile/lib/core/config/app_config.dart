/// Compile-time configuration, supplied with `--dart-define`.
///
/// dart-define values are baked into the binary while it is built, so there is
/// no config file on disk to read, leak, or edit after the app has shipped —
/// and no runtime surface for a tampered value to slip through.
class AppConfig {
  AppConfig._();

  /// Base URL of the HRMS API, including the `/api/v1` prefix.
  ///
  /// `10.0.2.2` is the host loopback address as seen from the Android
  /// emulator, which is where `php artisan serve` listens during development.
  /// A real device has to reach the machine over the LAN instead:
  ///
  ///     flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1
  ///
  /// Not configurable at runtime: the app has exactly one server to talk to,
  /// and a UI that could repoint it would turn a device into a credential
  /// forwarder for whoever can edit that setting.
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );
}
