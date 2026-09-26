import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/network/api_exception.dart';

/// Builds the failure the API (or the network on the way to it) would hand
/// over. `status: null` with no body models "never reached a server".
DioException failure({
  int? status,
  Object? body,
  DioExceptionType type = DioExceptionType.badResponse,
  Map<String, List<String>> headers = const <String, List<String>>{},
}) {
  final requestOptions = RequestOptions(path: '/api/v1/auth/login');

  return DioException(
    requestOptions: requestOptions,
    type: type,
    response: (status == null && body == null)
        ? null
        : Response<dynamic>(
            requestOptions: requestOptions,
            statusCode: status,
            data: body,
            headers: Headers.fromMap(headers),
          ),
  );
}

void main() {
  group('apiExceptionFrom', () {
    test('reads a 401 envelope as the server wrote it', () {
      final exception = apiExceptionFrom(
        failure(
          status: 401,
          body: <String, dynamic>{
            'success': false,
            'message': 'The email or password you entered is incorrect.',
            'errors': <String, dynamic>{},
          },
        ),
      );

      expect(exception.statusCode, 401);
      expect(exception.isUnauthenticated, isTrue);
      expect(
        exception.message,
        'The email or password you entered is incorrect.',
      );
      expect(exception.errors, isEmpty);
      expect(exception.retryAfter, isNull);
    });

    test('turns Laravel\'s array-valued errors into a field map', () {
      final exception = apiExceptionFrom(
        failure(
          status: 422,
          body: <String, dynamic>{
            'success': false,
            'message': 'The given data was invalid. (and 1 more errors)',
            'errors': <String, dynamic>{
              'email': <String>['The email field must be a valid email address.'],
              'password': <String>['The password field must be at least 8 characters.'],
            },
          },
        ),
      );

      expect(exception.isValidation, isTrue);
      expect(exception.errors, <String, String>{
        'email': 'The email field must be a valid email address.',
        'password': 'The password field must be at least 8 characters.',
      });
      // The banner still works: the summary is what the form shows above the
      // inputs while `errors` draws under them.
      expect(exception.message, contains('and 1 more errors'));
    });

    test('falls back to a usable message when the envelope carries none', () {
      final exception = apiExceptionFrom(
        failure(
          status: 400,
          body: <String, dynamic>{'success': false, 'errors': <String, dynamic>{}},
        ),
      );

      expect(exception.message, 'Something went wrong. Please try again.');
      expect(exception.errors, isEmpty);
    });

    test('parses Retry-After on a throttled request', () {
      final exception = apiExceptionFrom(
        failure(
          status: 429,
          body: <String, dynamic>{
            'success': false,
            'message': 'Too many attempts. Please wait a moment and try again.',
            'errors': <String, dynamic>{},
          },
          headers: const <String, List<String>>{'retry-after': <String>['37']},
        ),
      );

      expect(exception.isRateLimited, isTrue);
      expect(exception.retryAfter, const Duration(seconds: 37));
    });

    test('a timeout never blames the user for it', () {
      final exception = apiExceptionFrom(
        failure(status: null, body: null, type: DioExceptionType.connectionTimeout),
      );

      expect(exception.statusCode, 0);
      expect(exception.message, 'Could not reach the server. Check your connection and try again.');
    });

    test('a non-envelope body is not shown to anyone as prose', () {
      final exception = apiExceptionFrom(
        failure(status: 502, body: '<html><body>Bad gateway</body></html>'),
      );

      expect(exception.statusCode, 502);
      expect(exception.message, 'The server returned an unexpected response. Please try again.');
      expect(exception.message, isNot(contains('html')));
    });

    test('a throttled response without an envelope still reports the wait', () {
      final exception = apiExceptionFrom(
        failure(
          status: 429,
          body: 'slow down',
          headers: const <String, List<String>>{'retry-after': <String>['15']},
        ),
      );

      expect(exception.isRateLimited, isTrue);
      expect(exception.retryAfter, const Duration(seconds: 15));
    });
  });
}
