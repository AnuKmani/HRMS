import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_site_activity_repository.dart';
import '../domain/site_activity_report.dart';
import '../../sites/domain/site.dart';

/// The activity reports this session may read.
///
/// Filtered by the server, not here: `employee`, `project`, `site`,
/// `work_category`, `date_from`/`date_to` and `status` are all columns the
/// query builder understands *and* scopes, so a client-side `where` would
/// both duplicate the rules and show rows the API would have refused.
final siteActivityReportsListProvider =
    NotifierProvider<
      SiteActivityReportsListController,
      ListState<SiteActivityReport>
    >(SiteActivityReportsListController.new);

class SiteActivityReportsListController
    extends PagedListController<SiteActivityReport> {
  @override
  Future<PageResult<SiteActivityReport>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) =>
      ref.watch(siteActivityRepositoryProvider).list(page: page, query: query);
}

/// The sites a form may file against.
///
/// A deliberately different list from `sitesPickerProvider`. Every other
/// picker in the app reads `GET /sites`, which sits behind `sites.view` —
/// a permission a field worker does not hold and should not need to file a
/// note about the site they are standing on. `reportable-sites` answers the
/// narrower question ("where may *this* session report?") for exactly that
/// audience, so the form works for the person it was built for without
/// widening the directory.
final reportableSitesPickerProvider =
    NotifierProvider<ReportableSitesController, ListState<Site>>(
      ReportableSitesController.new,
    );

class ReportableSitesController extends PagedListController<Site> {
  @override
  Future<PageResult<Site>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref
      .watch(siteActivityRepositoryProvider)
      .reportableSites(page: page, query: query);

  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'per_page': 100,
    'sort': 'name',
  };
}
