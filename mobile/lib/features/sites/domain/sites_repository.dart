import '../../../core/data/page_result.dart';
import 'site.dart';

/// How the sites feature reaches the server.
abstract class SitesRepository {
  Future<PageResult<Site>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Site> find(int id);

  Future<Site> create(Map<String, Object?> body);

  Future<Site> update(int id, Map<String, Object?> body);

  /// Refused with a 422 while assignment history still points at this site.
  /// There is no `DELETE /employee-site-assignments/{id}`: the rows are the
  /// record of who worked where, and closing one is a status change, not a
  /// removal.
  Future<void> remove(int id);
}
