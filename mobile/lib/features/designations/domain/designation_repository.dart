import '../../../core/data/page_result.dart';
import 'designation.dart';

/// How the designations feature reaches the server.
abstract class DesignationsRepository {
  Future<PageResult<Designation>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Designation> find(int id);

  Future<Designation> create(Map<String, Object?> body);

  Future<Designation> update(int id, Map<String, Object?> body);

  /// Soft-deleted server-side, and refused with a 422 while any employee
  /// still holds this designation — history is not something a delete may
  /// quietly invalidate.
  Future<void> remove(int id);
}
