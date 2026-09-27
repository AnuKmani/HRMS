import '../../../core/data/page_result.dart';
import 'holiday.dart';

/// How the holiday feature reaches the server.
///
/// There is deliberately no `remove`. The API has no `DELETE /holidays`
/// either — a day that was declared off happened, and the way to stop it
/// counting is `status = inactive`, not a hole in the record. Offering a
/// delete button here would be offering something the server cannot honour.
abstract class HolidaysRepository {
  Future<PageResult<Holiday>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Holiday> find(int id);

  Future<Holiday> create(Map<String, Object?> body);

  Future<Holiday> update(int id, Map<String, Object?> body);
}
