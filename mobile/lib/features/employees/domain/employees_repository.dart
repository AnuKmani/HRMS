import '../../../core/data/page_result.dart';
import 'employee.dart';

/// How the employees feature reaches the server.
abstract class EmployeesRepository {
  Future<PageResult<Employee>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Employee> find(int id);

  Future<Employee> create(Map<String, Object?> body);

  Future<Employee> update(int id, Map<String, Object?> body);

  /// Soft-deleted server-side; the record stays in the database so anything
  /// that referenced it keeps making sense.
  Future<void> remove(int id);
}
