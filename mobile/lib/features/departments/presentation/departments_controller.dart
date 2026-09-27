import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_departments_repository.dart';
import '../domain/department.dart';

/// The departments list screen.
final departmentsListProvider =
    NotifierProvider<DepartmentsListController, ListState<Department>>(
      DepartmentsListController.new,
    );

/// The departments a form dropdown needs — the same fetch, asking for a
/// page big enough to fill a picker rather than a page a screen can scroll.
///
/// Separate provider on purpose: sharing one would let a picker's search
/// rewrite the filter the list screen believes it is showing.
final departmentsPickerProvider =
    NotifierProvider<DepartmentsPickerController, ListState<Department>>(
      DepartmentsPickerController.new,
    );

class DepartmentsListController extends PagedListController<Department> {
  @override
  Future<PageResult<Department>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(departmentsRepositoryProvider).list(page: page, query: query);
}

class DepartmentsPickerController extends DepartmentsListController {
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'per_page': 100,
    'sort': 'name',
  };
}
