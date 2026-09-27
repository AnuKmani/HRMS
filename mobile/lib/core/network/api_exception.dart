import 'package:dio/dio.dart';

/// One failure shape for every way a request can go wrong.
///
/// The API always answers with `{success, message, errors}`; this is that
/// envelope for the unhappy path, plus the status code it arrived with.
/// Failures that never reached the API at all — a timeout, a refused
/// connection — are given status code 0 and copy written here rather than on
/// the server.
///
/// [message] is safe to render verbatim: the backend's exception renderer
/// (see bootstrap/app.php) never puts a stack trace, SQL statement, model
/// class or internal path in it, and this class never adds one either.
class ApiException implements Exception {
  const ApiException({
    required this.statusCode,
    required this.message,
    this.errors = const <String, String>{},
    this.retryAfter,
  });

  /// HTTP status, or 0 when the request produced none.
  final int statusCode;

  /// Human-readable and safe to show the user as-is.
  final String message;

  /// Field => message, exactly as Laravel returned it. Empty for failures
  /// that have nothing to say about a particular input.
  final Map<String, String> errors;

  /// Parsed from `Retry-After`. Present on 429 only, and only when the
  /// server actually sent the header.
  final Duration? retryAfter;

  bool get isUnauthenticated => statusCode == 401;

  bool get isValidation => statusCode == 422;

  bool get isRateLimited => statusCode == 429;

  @override
  String toString() => 'ApiException($statusCode): $message';
}

/// Reads a failed request into an [ApiException].
///
/// The normal path is an envelope, and the server's own message is copied
/// across untouched. Everything else — a captive portal answering with HTML,
/// a proxy returning an empty body, a timeout with no response at all — is
/// mapped to copy written here, because none of that raw output should ever
/// be shown to a person as though it were a sentence.
ApiException apiExceptionFrom(DioException failure) {
  final response = failure.response;
  final body = response?.data;

  if (body is Map<String, dynamic> && body['success'] == false) {
    final message = body['message'];

    return ApiException(
      statusCode: response?.statusCode ?? 0,
      message: message is String && message.isNotEmpty
          ? message
          : 'Something went wrong. Please try again.',
      errors: _fieldErrors(body['errors']),
      retryAfter: _retryAfter(response),
    );
  }

  return switch (failure.type) {
    DioExceptionType.connectionTimeout ||
    DioExceptionType.sendTimeout ||
    DioExceptionType.receiveTimeout ||
    DioExceptionType.connectionError => const ApiException(
      statusCode: 0,
      message:
          'Could not reach the server. Check your connection and try again.',
    ),
    _ => _unexpectedResponse(response),
  };
}

ApiException _unexpectedResponse(Response<dynamic>? response) {
  final statusCode = response?.statusCode ?? 0;

  // Throttled without an envelope can only come from something between the
  // app and Laravel, but Retry-After is a plain HTTP header and survives
  // that, so the wait can still be reported honestly.
  if (statusCode == 429) {
    return ApiException(
      statusCode: statusCode,
      message: 'Too many attempts. Please wait a moment and try again.',
      retryAfter: _retryAfter(response),
    );
  }

  return ApiException(
    statusCode: statusCode,
    message: 'The server returned an unexpected response. Please try again.',
  );
}

Map<String, String> _fieldErrors(Object? raw) {
  if (raw is! Map) return const <String, String>{};

  final errors = <String, String>{};

  for (final entry in raw.entries) {
    final message = _singleMessage(entry.value);
    if (entry.key is String && message != null) {
      errors[entry.key! as String] = message;
    }
  }

  return errors;
}

/// Laravel sends validation failures as `{"email": ["message"]}`. A bare
/// string is accepted too, so the parser does not depend on one exact
/// validator output shape.
String? _singleMessage(Object? value) {
  if (value is String) return value;

  if (value is List && value.isNotEmpty) {
    final first = value.first;
    if (first is String) return first;
  }

  return null;
}

Duration? _retryAfter(Response<dynamic>? response) {
  final raw = response?.headers.value('retry-after');
  if (raw == null) return null;

  final seconds = int.tryParse(raw.trim());
  return seconds == null ? null : Duration(seconds: seconds);
}

/// The response arrived, was not a failure, and still did not have the shape
/// this app knows how to read.
///
/// Status code 0 on purpose: this is not a server error the user could retry
/// away, it is a mismatch between what the app expects and what a proxy or an
/// un-upgraded backend handed back. Callers that distinguish "you are not
/// allowed" from "we do not understand" both need this to fall out of the
/// second bucket.
const ApiException unexpectedShapeException = ApiException(
  statusCode: 0,
  message:
      'The server sent a response this app does not understand. '
      'Please check for an app update.',
);
