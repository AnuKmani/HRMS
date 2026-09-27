import '../../../core/data/page_result.dart';
import 'project.dart';

/// How the projects feature reaches the server.
abstract class ProjectsRepository {
  Future<PageResult<Project>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Project> find(int id);

  Future<Project> create(Map<String, Object?> body);

  Future<Project> update(int id, Map<String, Object?> body);

  /// Refused with a 422 while the project still has sites under it — the
  /// sites are the history, and a delete that took them with it would be
  /// rewriting it.
  Future<void> remove(int id);
}
