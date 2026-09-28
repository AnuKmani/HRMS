import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_daily_site_report_repository.dart';
import '../domain/daily_site_report.dart';

/// The official site-day documents this session may read.
final dailySiteReportsListProvider =
    NotifierProvider<
      DailySiteReportsListController,
      ListState<DailySiteReport>
    >(DailySiteReportsListController.new);

class DailySiteReportsListController
    extends PagedListController<DailySiteReport> {
  @override
  Future<PageResult<DailySiteReport>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref
      .watch(dailySiteReportRepositoryProvider)
      .list(page: page, query: query);
}
