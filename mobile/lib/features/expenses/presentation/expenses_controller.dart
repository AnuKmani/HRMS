import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_expense_repository.dart';
import '../domain/expense.dart';
import '../domain/expense_category.dart';

/// The claims the list screen shows.
///
/// The filters are query parameters and not a Dart-side `where` over the
/// page, because the server owns both the scoping and the totals: a list
/// narrowed in the browser would still be reporting the unfiltered `total`
/// underneath it, and "12 of 40" would then be a number nobody could act on.
final expenseListProvider =
    NotifierProvider<ExpenseListController, ListState<Expense>>(
      ExpenseListController.new,
    );

/// Categories for the form's picker — the whole active vocabulary in one
/// request, which is why it has no page number to fetch.
final expenseCategoriesPickerProvider =
    NotifierProvider<
      ExpenseCategoriesPickerController,
      ListState<ExpenseCategory>
    >(ExpenseCategoriesPickerController.new);

class ExpenseListController extends PagedListController<Expense> {
  @override
  Future<PageResult<Expense>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(expenseRepositoryProvider).list(page: page, query: query);
}

class ExpenseCategoriesPickerController
    extends PagedListController<ExpenseCategory> {
  /// The categories endpoint is `GET /expense-categories` and nothing else —
  /// no `sort`, no `per_page`, no filters beyond `all`. Sending parameters it
  /// ignores would read as though they did something.
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{};

  @override
  Future<PageResult<ExpenseCategory>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) async {
    final rows = await ref
        .watch(expenseRepositoryProvider)
        .categories(query: query);

    // One response, one page, and the picker's "load more" never fires: a
    // vocabulary of six does not need a second page, and pretending it has
    // one would put a "Load more" tile under four rows.
    return PageResult<ExpenseCategory>(
      items: rows,
      currentPage: 1,
      lastPage: 1,
      perPage: rows.length,
      total: rows.length,
      hasNext: false,
    );
  }
}
