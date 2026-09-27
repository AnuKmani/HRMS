import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_designations_repository.dart';
import '../domain/designation.dart';

final designationsListProvider =
    NotifierProvider<DesignationsListController, ListState<Designation>>(
  DesignationsListController.new,
);

/// Designation ids with their titles, for the employee form.
final designationsPickerProvider =
    NotifierProvider<DesignationsPickerController, ListState<Designation>>(
  DesignationsPickerController.new,
);

class DesignationsListController extends PagedListController<Designation> {
  @override
  Future<PageResult<Designation>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) =>
      ref
          .watch(designationsRepositoryProvider)
          .list(page: page, query: query);
}

class DesignationsPickerController extends DesignationsListController {
  @override
  Map<String, Object?> get baseQuery =>
      const <String, Object?>{'per_page': 100, 'sort': 'name'};
}
