import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_document_repository.dart';
import '../domain/document_type.dart';
import '../domain/employee_document.dart';

/// The documents the list screen shows.
///
/// The filters are query parameters and not a Dart-side `where` over the
/// page, because the server owns both the scoping and the totals: a list
/// narrowed in the browser would still be reporting the unfiltered `total`
/// underneath it, and "12 of 40" would then be a number nobody could act on.
final documentListProvider =
    NotifierProvider<DocumentListController, ListState<EmployeeDocument>>(
      DocumentListController.new,
    );

/// `GET /employee-documents/expiring` — the report, kept apart from the
/// directory above because it is a *different question with a different
/// permission*: "what is about to lapse, across everyone I may see?" rather
/// than "show me the files". Two providers rather than one with a flag is
/// the difference between the two staying honest about their own totals.
final documentExpiryProvider =
    NotifierProvider<DocumentExpiryController, ListState<EmployeeDocument>>(
      DocumentExpiryController.new,
    );

/// Document types for the upload form's picker — the whole active
/// vocabulary in one request, which is why there is no page number to fetch.
final documentTypesPickerProvider =
    NotifierProvider<DocumentTypesPickerController, ListState<DocumentType>>(
      DocumentTypesPickerController.new,
    );

class DocumentListController extends PagedListController<EmployeeDocument> {
  /// `expiry` is this screen's own one-value selector; `expired` and
  /// `expiring_soon` are the two booleans the API reads.
  ///
  /// Translated here rather than at the tap for two reasons: the key stays
  /// in `state.query` so the dropdown has a value to draw when it comes
  /// back, and one function has to be right rather than three call sites.
  /// A value is sent as `1` because `EmployeeDocumentController::truthy()`
  /// reads `1`, `true`, `yes` — and clearing the choice removes the key
  /// instead of sending `false`, because "not filtering" and "filtering on
  /// false" are different questions to the server.
  @override
  Future<PageResult<EmployeeDocument>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) {
    final wire = Map<String, Object?>.of(query);
    final expiry = wire.remove('expiry');

    if (expiry is String && expiry.isNotEmpty) {
      wire[expiry] = '1';
    }

    return ref.watch(documentRepositoryProvider).list(page: page, query: wire);
  }
}

class DocumentExpiryController extends PagedListController<EmployeeDocument> {
  /// `within` widens the window from "already gone" to "soon", in days. It
  /// is a base query rather than a filter the screen sets, because the report
  /// has one window and a screen that sent a different one would be reading
  /// rows the server had already scoped differently.
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{'within': 90};

  @override
  Future<PageResult<EmployeeDocument>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) =>
      ref.watch(documentRepositoryProvider).expiring(page: page, query: query);
}

class DocumentTypesPickerController extends PagedListController<DocumentType> {
  /// `active_only` because a form may not file under a retired type — the
  /// choice is filtered by the server too (`Rule::exists(...)->where(status)`),
  /// so this is the picker declining to offer a door that would only come
  /// back as a 422.
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'active_only': true,
  };

  @override
  Future<PageResult<DocumentType>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) async {
    final rows = await ref
        .watch(documentRepositoryProvider)
        .types(query: query);

    // One response, one page, and the picker's "load more" never fires: a
    // vocabulary of nine does not need a second page, and pretending it had
    // one would put a "Load more" tile under four rows.
    return PageResult<DocumentType>(
      items: rows,
      currentPage: 1,
      lastPage: 1,
      perPage: rows.length,
      total: rows.length,
      hasNext: false,
    );
  }
}
