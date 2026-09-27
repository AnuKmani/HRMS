import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_sites_repository.dart';
import '../domain/site.dart';

final sitesListProvider =
    NotifierProvider<SitesListController, ListState<Site>>(
      SitesListController.new,
    );

/// Site names for the employee form.
final sitesPickerProvider =
    NotifierProvider<SitesPickerController, ListState<Site>>(
      SitesPickerController.new,
    );

class SitesListController extends PagedListController<Site> {
  @override
  Future<PageResult<Site>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(sitesRepositoryProvider).list(page: page, query: query);
}

class SitesPickerController extends SitesListController {
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'per_page': 100,
    'sort': 'name',
  };
}
