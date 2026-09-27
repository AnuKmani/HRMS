import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/project.dart';
import '../domain/projects_repository.dart';

final projectsRepositoryProvider = Provider<ProjectsRepository>(
  (ref) => ApiProjectsRepository(ref.watch(apiClientProvider)),
);

class ApiProjectsRepository implements ProjectsRepository {
  ApiProjectsRepository(this._client);

  static const _path = '/projects';

  final ApiClient _client;

  @override
  Future<PageResult<Project>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<Project>.fromEnvelope(envelope, Project.fromJson);
  }

  @override
  Future<Project> find(int id) async {
    final envelope = await _client.get('$_path/$id');

    return _one(envelope.data);
  }

  @override
  Future<Project> create(Map<String, Object?> body) async {
    final envelope = await _client.post(_path, body: body);

    return _one(envelope.data);
  }

  @override
  Future<Project> update(int id, Map<String, Object?> body) async {
    final envelope = await _client.put('$_path/$id', body: body);

    return _one(envelope.data);
  }

  @override
  Future<void> remove(int id) => _client.delete('$_path/$id');

  Project _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;

    return Project.fromJson(data);
  }
}
