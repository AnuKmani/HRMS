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
  /// Only for a record created by mistake: refused (409) for anyone with
  /// history — they are marked as left instead.
  Future<void> remove(int id);

  /// The person has left: resigned or terminated, last working day.
  /// History is kept; their login stops.
  Future<Employee> offboard(int id, {required String status, String? exitDate});

  /// Back to active, login on again.
  Future<Employee> reinstate(int id);
}
