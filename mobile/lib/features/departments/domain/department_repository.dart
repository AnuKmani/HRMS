import '../../../core/data/page_result.dart';
import 'department.dart';

/// How the departments feature reaches the server.
///
/// Abstract so the screens can be tested against an in-memory double: nothing
/// about "does this list show its spinner?" should need a socket.
abstract class DepartmentsRepository {
  Future<PageResult<Department>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Department> find(int id);

  Future<Department> create(Map<String, Object?> body);

  Future<Department> update(int id, Map<String, Object?> body);

  /// Soft-deleted server-side. Refused by the API with a 422 when the
  /// department still has employees.
  Future<void> remove(int id);
}
