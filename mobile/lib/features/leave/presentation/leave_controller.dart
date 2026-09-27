import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_leave_repository.dart';
import '../domain/leave_balance.dart';
import '../domain/leave_request.dart';
import '../domain/leave_type.dart';

/// The leave requests the list screen shows.
///
/// The filters this list carries — status, type, year, the date range — are
/// the server's own parameters, so a filter that does something on screen is
/// a filter that did something in the query. Nothing is applied to a fetched
/// page in Dart: a "show only pending" toggle that hid rows the API had
/// returned would report a total that no longer matched what was drawn.
final leaveListProvider =
    NotifierProvider<LeaveListController, ListState<LeaveRequest>>(
      LeaveListController.new,
    );

class LeaveListController extends PagedListController<LeaveRequest> {
  @override
  Future<PageResult<LeaveRequest>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(leaveRepositoryProvider).list(page: page, query: query);
}

/// The leave types a form may choose from.
///
/// Separate from the request list on purpose: sharing one would let the
/// type dropdown's page size rewrite the filter the list believes it is
/// showing.
final leaveTypesProvider =
    NotifierProvider<LeaveTypesPickerController, ListState<LeaveType>>(
      LeaveTypesPickerController.new,
    );

class LeaveTypesPickerController extends PagedListController<LeaveType> {
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'per_page': 100,
    'sort': 'name',
    'status': 'active',
  };

  @override
  Future<PageResult<LeaveType>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(leaveRepositoryProvider).types(page: page, query: query);
}

/// The balance pots — one row per leave type for one employee for one year.
///
/// The endpoint answers differently depending on who is asking: an ordinary
/// employee gets their own rows, a manager who may read others gets theirs
/// and their team's. The screen does not restate that rule; it draws what
/// came back and names the person when the server said who it was.
final leaveBalancesProvider =
    NotifierProvider<LeaveBalancesController, ListState<LeaveBalance>>(
      LeaveBalancesController.new,
    );

class LeaveBalancesController extends PagedListController<LeaveBalance> {
  @override
  Future<PageResult<LeaveBalance>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(leaveRepositoryProvider).balances(page: page, query: query);
}
