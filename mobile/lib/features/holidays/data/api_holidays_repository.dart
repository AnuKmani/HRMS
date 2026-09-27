import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/holiday.dart';
import '../domain/holidays_repository.dart';

final holidaysRepositoryProvider = Provider<HolidaysRepository>(
  (ref) => ApiHolidaysRepository(ref.watch(apiClientProvider)),
);

/// `HolidaysRepository` over the real HTTP client.
///
/// No `remove`: see [HolidaysRepository] for why the verb does not exist.
class ApiHolidaysRepository implements HolidaysRepository {
  ApiHolidaysRepository(this._client);

  static const _path = '/holidays';

  final ApiClient _client;

  @override
  Future<PageResult<Holiday>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Holiday>.fromEnvelope(envelope, Holiday.fromJson);
  }

  @override
  Future<Holiday> find(int id) async =>
      _one((await _client.get('$_path/$id')).data);

  @override
  Future<Holiday> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<Holiday> update(int id, Map<String, Object?> body) async =>
      _one((await _client.put('$_path/$id', body: body)).data);

  Holiday _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Holiday.fromJson(data);
  }
}
