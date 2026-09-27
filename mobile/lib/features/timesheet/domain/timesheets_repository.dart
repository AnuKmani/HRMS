import '../../../core/data/page_result.dart';
import 'timesheet.dart';

/// How the timesheet feature reaches the server.
///
/// Three methods and no more, which is the point: a timesheet is generated,
/// read and read again. There is no `create`, no `update` and no
/// `remove` because the API has none — the rows are derived from
/// attendance, and a "timesheet editor" would be a second source of truth
/// for how long somebody worked.
abstract class TimesheetsRepository {
  Future<PageResult<Timesheet>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Timesheet> find(int id);

  /// Regenerates every day in `[from, to]` (inclusive, `YYYY-MM-DD`) from
  /// attendance.
  ///
  /// Idempotent server-side: running it twice over the same days updates the
  /// snapshots rather than duplicating them, which is why there is no
  /// "generate once" guard in the UI — a second press is a refresh.
  Future<void> generate({
    required String from,
    required String to,
    int? employeeId,
  });
}
