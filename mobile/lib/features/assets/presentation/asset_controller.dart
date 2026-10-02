import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_asset_repository.dart';
import '../domain/asset.dart';
import '../domain/asset_assignment.dart';
import '../domain/asset_type.dart';

/// The assets the register shows.
///
/// The filters are query parameters and not a Dart-side `where` over the
/// page, because the server owns both the row scope and the totals: a
/// register narrowed in the browser would still be reporting the unfiltered
/// `total` underneath it, and "12 of 40" would then be a number nobody
/// could act on.
final assetListProvider =
    NotifierProvider<AssetListController, ListState<Asset>>(
      AssetListController.new,
    );

/// `GET /asset-assignments` — the cross-employee log, a different question
/// ("who has held what?") behind a different permission
/// (`assets.history.view` on top of `assets.view`). Two providers rather
/// than one with a flag is the difference between the two staying honest
/// about their own totals.
final assetHistoryProvider =
    NotifierProvider<AssetHistoryController, ListState<AssetAssignment>>(
      AssetHistoryController.new,
    );

/// Asset types for the register's filter and the form's picker — the whole
/// vocabulary in one request, which is why there is no page number to fetch.
final assetTypesPickerProvider =
    NotifierProvider<AssetTypesPickerController, ListState<AssetType>>(
      AssetTypesPickerController.new,
    );

class AssetListController extends PagedListController<Asset> {
  @override
  Future<PageResult<Asset>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(assetRepositoryProvider).list(page: page, query: query);
}

class AssetHistoryController extends PagedListController<AssetAssignment> {
  /// The log defaults to the open hand-overs — "what is out right now" is
  /// the question that gets asked first — and a reader who wants the closed
  /// ones filters for them rather than scrolling past everything.
  @override
  Map<String, Object?> get initialQuery => const <String, Object?>{
    'status': 'active',
  };

  @override
  Future<PageResult<AssetAssignment>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) =>
      ref.watch(assetRepositoryProvider).assignments(page: page, query: query);
}

class AssetTypesPickerController extends PagedListController<AssetType> {
  @override
  Future<PageResult<AssetType>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) async {
    final rows = await ref.watch(assetRepositoryProvider).types(query: query);

    // One response, one page, and the picker's "load more" never fires: a
    // vocabulary of seven does not need a second page, and pretending it had
    // one would put a "Load more" tile under four rows.
    return PageResult<AssetType>(
      items: rows,
      currentPage: 1,
      lastPage: 1,
      perPage: rows.length,
      total: rows.length,
      hasNext: false,
    );
  }
}
