import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_timesheets_repository.dart';
import '../domain/timesheet.dart';

/// The timesheets the list screen shows.
///
/// The filters are the endpoint's own parameters, applied in the query.
/// Nothing is filtered in Dart afterwards: a page the API returned and the
/// screen then hid would make `total` a lie about what is drawn.
final timesheetsListProvider =
    NotifierProvider<TimesheetsListController, ListState<Timesheet>>(
      TimesheetsListController.new,
    );

class TimesheetsListController extends PagedListController<Timesheet> {
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'sort': 'timesheet_date',
    'direction': 'desc',
  };

  @override
  Future<PageResult<Timesheet>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(timesheetsRepositoryProvider).list(page: page, query: query);
}
