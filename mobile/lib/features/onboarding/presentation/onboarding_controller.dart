import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_onboarding_repository.dart';
import '../domain/onboarding.dart';

/// Where every starter stands, in one list.
///
/// The filter is a query parameter rather than a Dart-side `where`, because
/// the server owns both the row scope and the totals: a list narrowed in the
/// browser would still be reporting the unfiltered `total` underneath it.
///
/// `status` deserves a note. The API treats `draft` as *"nobody has opened
/// this one yet"* and matches it against employees who have no record at all
/// — so filtering to `draft` shows the starters nobody has begun, rather than
/// hiding them, which is exactly the list HR needs and exactly what a naive
/// join would have lost.
final onboardingListProvider =
    NotifierProvider<OnboardingListController, ListState<Onboarding>>(
      OnboardingListController.new,
    );

class OnboardingListController extends PagedListController<Onboarding> {
  @override
  Future<PageResult<Onboarding>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(onboardingRepositoryProvider).list(page: page, query: query);
}
