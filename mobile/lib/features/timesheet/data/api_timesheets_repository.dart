import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/timesheet.dart';
import '../domain/timesheets_repository.dart';

final timesheetsRepositoryProvider = Provider<TimesheetsRepository>(
  (ref) => ApiTimesheetsRepository(ref.watch(apiClientProvider)),
);

/// `TimesheetsRepository` over the real HTTP client.
///
/// The route `POST /timesheets/generate` is registered *before*
/// `GET /timesheets/{timesheet}` on the server, and the literal is spelled
/// in full here so nothing in this app ever presents a path that could be
/// read as an id.
class ApiTimesheetsRepository implements TimesheetsRepository {
  ApiTimesheetsRepository(this._client);

  static const _path = '/timesheets';

  final ApiClient _client;

  @override
  Future<PageResult<Timesheet>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Timesheet>.fromEnvelope(envelope, Timesheet.fromJson);
  }

  @override
  Future<Timesheet> find(int id) async {
    final envelope = await _client.get('$_path/$id');
    final data = envelope.data;

    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Timesheet.fromJson(data);
  }

  @override
  Future<void> generate({
    required String from,
    required String to,
    int? employeeId,
  }) async {
    await _client.post(
      '$_path/generate',
      body: <String, Object?>{
        'from': from,
        'to': to,
        'employee_id': ?employeeId,
      },
    );
  }
}
