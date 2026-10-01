import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

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

  /// A POST carrying files — the check-in selfie, and a batch of site-report
  /// photographs.
  ///
  /// Written as a separate verb rather than letting `post` guess: `post`
  /// sends JSON, and a `FormData` handed to a JSON endpoint would arrive as
  /// an empty body with a multipart header, which is the kind of failure
  /// that looks like a server bug for an hour. Fields and the files are
  /// named explicitly so the caller's payload is readable at the call site.
  ///
  /// A value may be one [MultipartFile] or a **list** of them. `FormData`
  /// turns a list into `photos[0]`, `photos[1]`, … which is exactly the
  /// shape PHP reads back as one array under one key, so "these six frames
  /// are one batch with one caption" needs no wrapper object and no request
  /// per frame.
  Future<ApiEnvelope> postMultipart(
    String path, {
    required Map<String, Object?> fields,
    Map<String, Object>? files,
  }) {
    final form = <String, Object?>{...fields};

    files?.forEach((name, file) => form[name] = file);

    return _send(() => dio.post<dynamic>(path, data: FormData.fromMap(form)));
  }

  /// The body of an endpoint whose body is *not* the envelope.
  ///
  /// One caller today: `GET /daily-site-reports/{id}/pdf`, which answers with
  /// a PDF stream rather than `{success, message, data}`. Deliberately a
  /// separate method rather than a flag on [get] — every other response in
  /// this app goes through `_envelopeOf`, and a mode that skipped that check
  /// for one caller would be a mode that could silently skip it for two.
  ///
  /// The request asks for bytes and only bytes: a PDF handed to a JSON
  /// decoder comes back as a mangled string, and a `content-type` somebody
  /// guessed wrong must not decide whether the document is readable.
  Future<Uint8List> bytes(String path) async {
    try {
      final response = await dio.get<List<int>>(
        path,
        options: Options(responseType: ResponseType.bytes),
      );

      final body = response.data;
      if (body == null) throw unexpectedShapeException;

      return Uint8List.fromList(body);
    } on DioException catch (failure) {
      // A failure arrives with the same bytes this method was built to read,
      // so the envelope is still there — just not in the shape
      // `apiExceptionFrom` expects. Decoding it first is what lets a 403 on
      // the PDF report *the server's* sentence ("you may not export this
      // document") instead of a generic "unexpected response", which would
      // tell the person nothing about what to do next.
      final body = failure.response?.data;

      if (body is List<int>) {
        try {
          failure.response?.data = jsonDecode(utf8.decode(body));
        } catch (_) {
          // Not JSON after all — a proxy's HTML error page, say. The
          // generic wording below is the honest answer for that.
        }
      }

      throw apiExceptionFrom(failure);
    }
  }

  Future<ApiEnvelope> put(String path, {Object? body}) =>
      _send(() => dio.put<dynamic>(path, data: body));

  /// [postMultipart]'s twin for `PUT`, which is how an existing document's
  /// file is replaced.
  ///
  /// Separate for the same reason: `put` sends JSON, and the update route
  /// accepts multipart only when a file is actually attached — a client that
  /// guessed between the two would send the wrong body to a route that
  /// accepts it as an empty object rather than complaining.
  Future<ApiEnvelope> putMultipart(
    String path, {
    required Map<String, Object?> fields,
    Map<String, Object>? files,
  }) {
    final form = <String, Object?>{...fields};

    files?.forEach((name, file) => form[name] = file);

    return _send(() => dio.put<dynamic>(path, data: FormData.fromMap(form)));
  }

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
