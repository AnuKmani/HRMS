import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/designation.dart';
import '../domain/designation_repository.dart';

final designationsRepositoryProvider = Provider<DesignationsRepository>(
  (ref) => ApiDesignationsRepository(ref.watch(apiClientProvider)),
);

class ApiDesignationsRepository implements DesignationsRepository {
  ApiDesignationsRepository(this._client);

  static const _path = '/designations';

  final ApiClient _client;

  @override
  Future<PageResult<Designation>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Designation>.fromEnvelope(envelope, Designation.fromJson);
  }

  @override
  Future<Designation> find(int id) async {
    final envelope = await _client.get('$_path/$id');

    return _one(envelope.data);
  }

  @override
  Future<Designation> create(Map<String, Object?> body) async {
    final envelope = await _client.post(_path, body: body);

    return _one(envelope.data);
  }

  @override
  Future<Designation> update(int id, Map<String, Object?> body) async {
    final envelope = await _client.put('$_path/$id', body: body);

    return _one(envelope.data);
  }

  @override
  Future<void> remove(int id) => _client.delete('$_path/$id');

  Designation _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Designation.fromJson(data);
  }
}
