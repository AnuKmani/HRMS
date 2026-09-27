import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/department.dart';
import '../domain/department_repository.dart';

final departmentsRepositoryProvider = Provider<DepartmentsRepository>(
  (ref) => ApiDepartmentsRepository(ref.watch(apiClientProvider)),
);

/// `DepartmentsRepository` over the real HTTP client.
///
/// Every verb translates the envelope once and lets `PageResult` / the model
/// decide what a payload that does not match means — no repository here
/// swallows a malformed response and hands back an empty list, because
/// "nothing exists" and "nothing was understood" have to stay different
/// answers for the screens to draw them differently.
class ApiDepartmentsRepository implements DepartmentsRepository {
  ApiDepartmentsRepository(this._client);

  static const _path = '/departments';

  final ApiClient _client;

  @override
  Future<PageResult<Department>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Department>.fromEnvelope(envelope, Department.fromJson);
  }

  @override
  Future<Department> find(int id) async {
    final envelope = await _client.get('$_path/$id');

    return _one(envelope.data);
  }

  @override
  Future<Department> create(Map<String, Object?> body) async {
    final envelope = await _client.post(_path, body: body);

    return _one(envelope.data);
  }

  @override
  Future<Department> update(int id, Map<String, Object?> body) async {
    final envelope = await _client.put('$_path/$id', body: body);

    return _one(envelope.data);
  }

  @override
  Future<void> remove(int id) => _client.delete('$_path/$id');

  Department _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Department.fromJson(data);
  }
}
