import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app_config.dart';

/// Where the API lives — and, in test builds only, a way to change it on
/// the phone when the laptop running `php artisan serve` gets a new Wi-Fi
/// address.
///
/// Off in production: an app whose server could be repointed from its own
/// sign-in screen would send people's passwords wherever somebody typed.
/// A build turns it on with `--dart-define=ALLOW_SERVER_SETTING=true`.
abstract class ServerAddressStore {
  String? read();
  Future<void> write(String? url);
}

class MemoryServerAddressStore implements ServerAddressStore {
  String? value;

  @override
  String? read() => value;

  @override
  Future<void> write(String? url) async => value = url;
}

final serverAddressStoreProvider = Provider<ServerAddressStore>(
  (ref) => MemoryServerAddressStore(),
);

/// Whether this build lets the tester change the server address.
final serverSettingAllowedProvider = Provider<bool>(
  (ref) => AppConfig.allowServerSetting,
);

final serverAddressProvider = NotifierProvider<ServerAddressController, String>(
  ServerAddressController.new,
);

class ServerAddressController extends Notifier<String> {
  @override
  String build() {
    if (!ref.watch(serverSettingAllowedProvider)) return AppConfig.baseUrl;
    return ref.watch(serverAddressStoreProvider).read() ?? AppConfig.baseUrl;
  }

  /// Save what the tester typed — `192.168.1.170`, `192.168.1.170:8000`
  /// or a full `http://…/api/v1` all work. Null or blank goes back to the
  /// address the app was built with.
  Future<void> set(String? typed) async {
    if (!ref.read(serverSettingAllowedProvider)) return;
    final url = typed == null || typed.trim().isEmpty
        ? null
        : normaliseServerAddress(typed);
    await ref.read(serverAddressStoreProvider).write(url);
    state = url ?? AppConfig.baseUrl;
  }
}

/// `192.168.1.170` → `http://192.168.1.170:8000/api/v1`.
String normaliseServerAddress(String typed) {
  var value = typed.trim();
  if (!value.contains('://')) value = 'http://$value';
  final uri = Uri.parse(value);
  final port = uri.hasPort ? uri.port : (uri.scheme == 'https' ? 443 : 8000);
  var path = uri.path;
  if (path.isEmpty || path == '/') path = '/api/v1';
  if (path.endsWith('/')) path = path.substring(0, path.length - 1);
  return Uri(
    scheme: uri.scheme,
    host: uri.host,
    port: port,
    path: path,
  ).toString();
}

/// `http://host:8000/api/v1` → `host:8000`, for showing on screen.
String serverAddressLabel(String url) {
  final uri = Uri.tryParse(url);
  if (uri == null || uri.host.isEmpty) return url;
  return uri.hasPort ? '${uri.host}:${uri.port}' : uri.host;
}

/// Asks the server's own health page (`/up`) whether it is there.
/// Returns null when it answered, or a short reason when it did not.
typedef ServerProbe = Future<String?> Function(String apiBaseUrl);

final serverProbeProvider = Provider<ServerProbe>((ref) => probeServer);

Future<String?> probeServer(String apiBaseUrl) async {
  final api = Uri.parse(apiBaseUrl);
  final up = api.replace(path: '/up');
  final dio = Dio(
    BaseOptions(
      connectTimeout: const Duration(seconds: 5),
      receiveTimeout: const Duration(seconds: 5),
    ),
  );
  try {
    final response = await dio.getUri<Object?>(up);
    final ok = (response.statusCode ?? 0) < 400;
    return ok
        ? null
        : 'The server answered with an error (${response.statusCode}).';
  } on DioException catch (failure) {
    return switch (failure.type) {
      DioExceptionType.connectionTimeout || DioExceptionType.receiveTimeout =>
        'No answer from ${serverAddressLabel(apiBaseUrl)}. Is the laptop on '
            'the same Wi-Fi, and is the server running?',
      DioExceptionType.badResponse =>
        'The server answered with an error (${failure.response?.statusCode}).',
      _ =>
        'Could not connect to ${serverAddressLabel(apiBaseUrl)}. Check the '
            'address and that the server is running.',
    };
  } finally {
    dio.close();
  }
}
