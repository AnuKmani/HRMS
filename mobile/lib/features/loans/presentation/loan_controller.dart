import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_loan_repository.dart';
import '../domain/loan.dart';

/// The rows behind `GET /loans`.
///
/// Unfiltered by default rather than opening on `pending`: a borrower's list
/// opens on *their* loans and the API has already narrowed it to those, so
/// pre-filtering by a status would hide the completed one they came to
/// re-read. Approval queues filter from the chips on the screen instead.
final loanListProvider = NotifierProvider<LoanListController, ListState<Loan>>(
  LoanListController.new,
);

class LoanListController extends PagedListController<Loan> {
  @override
  Future<PageResult<Loan>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(loanRepositoryProvider).list(page: page, query: query);
}
