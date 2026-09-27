import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/employee.dart';
import '../domain/employees_repository.dart';

final employeesRepositoryProvider = Provider<EmployeesRepository>(
  (ref) => ApiEmployeesRepository(ref.watch(apiClientProvider)),
);

class ApiEmployeesRepository implements EmployeesRepository {
  ApiEmployeesRepository(this._client);

  static const _path = '/employees';

  final ApiClient _client;

  @override
  Future<PageResult<Employee>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Employee>.fromEnvelope(envelope, Employee.fromJson);
  }

  @override
  Future<Employee> find(int id) async {
    final envelope = await _client.get('$_path/$id');

    return _one(envelope.data);
  }

  @override
  Future<Employee> create(Map<String, Object?> body) async {
    final envelope = await _client.post(_path, body: body);

    return _one(envelope.data);
  }

  @override
  Future<Employee> update(int id, Map<String, Object?> body) async {
    final envelope = await _client.put('$_path/$id', body: body);

    return _one(envelope.data);
  }

  @override
  Future<void> remove(int id) => _client.delete('$_path/$id');

  Employee _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Employee.fromJson(data);
  }
}
