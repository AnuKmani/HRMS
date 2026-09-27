import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/app_config.dart';
import '../storage/token_store.dart';
import 'api_exception.dart';

final apiClientProvider = Provider<ApiClient>((ref) {
  final client = ApiClient(
    baseUrl: AppConfig.baseUrl,
    tokenStore: ref.watch(tokenStoreProvider),
  );

  ref.onDispose(client.dispose);

  return client;
});

/// The success half of the envelope: `{success, message, data}`.
class ApiEnvelope {
  const ApiEnvelope({required this.message, required this.data});

  final String message;

  /// `data` as the server sent it. Phase 3 endpoints always return an object
  /// here — ApiResponse builds `data` from `(object) []` when there is nothing
  /// to report — but the type is left honest rather than cast blind.
  final Object? data;
}

/// The HTTP layer, wrapped in the API's envelope rules.
///
/// Three jobs, and no others:
///
///  1. attach the bearer token to requests that should carry one;
///  2. guarantee that a failed request leaves as an [ApiException], never as a
///     raw [DioException] the UI would have to know how to interpret;
///  3. announce when a token this app presented has been rejected, so the
///     session can be dropped instead of leaving the user trusting a screen
///     whose credentials stopped being worth anything a moment ago.
///
/// Job 3 deliberately keys off *whether a token was attached*, not off the
/// status code alone: a rejected sign-in attempt sends no token, so the wrong
/// password on the login form can never be mistaken for an expired session.
class ApiClient {
  ApiClient({required String baseUrl, required TokenStore tokenStore})
    : dio = Dio(
        BaseOptions(
          baseUrl: baseUrl,
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 15),
          headers: <String, String>{'Accept': 'application/json'},
        ),
      ) {
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await tokenStore.read();

          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
            options.extra[_taggedToken] = true;
          }

          handler.next(options);
        },
        onError: (failure, handler) {
          final presented = failure.requestOptions.extra[_taggedToken] == true;
          final rejected = failure.response?.statusCode == 401;

          if (presented && rejected) {
            _sessionRejected.add(null);
          }

          handler.next(failure);
        },
      ),
    );
  }

  static const String _taggedToken = 'presented_token';

  final Dio dio;

  final StreamController<void> _sessionRejected =
      StreamController<void>.broadcast();

  /// Fires when the server rejects a token this app sent.
  ///
  /// Broadcast and therefore inert until someone listens: the auth controller
  /// subscribes when it is built, which happens before the first request.
  Stream<void> get sessionRejected => _sessionRejected.stream;

  Future<ApiEnvelope> get(String path, {Map<String, Object?>? query}) =>
      _send(() => dio.get<dynamic>(path, queryParameters: query));

  Future<ApiEnvelope> post(String path, {Object? body}) =>
      _send(() => dio.post<dynamic>(path, data: body));

  /// A POST carrying a file — the check-in selfie, and nothing else today.
  ///
  /// Written as a separate verb rather than letting `post` guess: `post`
  /// sends JSON, and a `FormData` handed to a JSON endpoint would arrive as
  /// an empty body with a multipart header, which is the kind of failure
  /// that looks like a server bug for an hour. Fields and the file are
  /// named explicitly so the caller's payload is readable at the call site.
  Future<ApiEnvelope> postMultipart(
    String path, {
    required Map<String, Object?> fields,
    Map<String, MultipartFile>? files,
  }) {
    final form = <String, Object?>{...fields};

    files?.forEach((name, file) => form[name] = file);

    return _send(() => dio.post<dynamic>(path, data: FormData.fromMap(form)));
  }

  Future<ApiEnvelope> put(String path, {Object? body}) =>
      _send(() => dio.put<dynamic>(path, data: body));

  /// Reserved for endpoints that legitimately have one.
  ///
  /// Employee site assignments deliberately have no `DELETE` route — posting
  /// history is append-only and closed with `PUT` — so nothing in Phase 4
  /// calls this for them. The verb exists so the *other* resources (whose
  /// rows are soft-deleted server-side) are not forced through `put`.
  Future<ApiEnvelope> delete(String path) =>
      _send(() => dio.delete<dynamic>(path));

  Future<ApiEnvelope> _send(Future<Response<dynamic>> Function() send) async {
    try {
      final response = await send();
      return _envelopeOf(response);
    } on DioException catch (failure) {
      throw apiExceptionFrom(failure);
    }
  }

  ApiEnvelope _envelopeOf(Response<dynamic> response) {
    final body = response.data;

    if (body is Map<String, dynamic>) {
      final message = body['message'];

      return ApiEnvelope(
        message: message is String ? message : '',
        data: body['data'],
      );
    }

    // A 200 whose body is not the envelope: a misconfigured proxy, or an
    // endpoint that was never migrated to ApiResponse. Refusing to guess is
    // the point — the alternative is a null check cascading through the
    // repository on a shape nobody verified.
    throw ApiException(
      statusCode: response.statusCode ?? 0,
      message:
          'The server sent a response this app does not understand. '
          'Please check for an app update.',
    );
  }

  void dispose() {
    _sessionRejected.close();
    dio.close(force: true);
  }
}
