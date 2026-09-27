import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_holidays_repository.dart';
import '../domain/holiday.dart';

/// The calendar the holiday screens show.
///
/// Readable by every signed-in account — "is the office open?" is not a
/// privileged question — so there is no permission gate behind this
/// provider. Writing is gated, and only on the screens that offer a write.
final holidaysListProvider =
    NotifierProvider<HolidaysListController, ListState<Holiday>>(
      HolidaysListController.new,
    );

class HolidaysListController extends PagedListController<Holiday> {
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'sort': 'date',
    'direction': 'desc',
  };

  @override
  Future<PageResult<Holiday>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(holidaysRepositoryProvider).list(page: page, query: query);
}
