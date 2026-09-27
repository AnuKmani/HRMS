import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_overtime_repository.dart';
import '../domain/overtime_request.dart';

/// The overtime claims the list screen shows.
///
/// `payroll_eligible` is one of the server's own filters, so a "payroll only"
/// toggle is a query parameter and not a Dart-side `where` over the page —
/// which keeps the total honest when a filter is on.
final overtimeListProvider =
    NotifierProvider<OvertimeListController, ListState<OvertimeRequest>>(
      OvertimeListController.new,
    );

class OvertimeListController extends PagedListController<OvertimeRequest> {
  @override
  Future<PageResult<OvertimeRequest>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(overtimeRepositoryProvider).list(page: page, query: query);
}
