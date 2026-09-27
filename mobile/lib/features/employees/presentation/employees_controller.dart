import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_employees_repository.dart';
import '../domain/employee.dart';

final employeesListProvider =
    NotifierProvider<EmployeesListController, ListState<Employee>>(
      EmployeesListController.new,
    );

/// Employees as an option list — reporting managers, site supervisors,
/// project managers.
///
/// Its own provider for the same reason every picker has one: a search typed
/// into a dropdown must not rewrite the filter the list screen is drawing.
final employeesPickerProvider =
    NotifierProvider<EmployeesPickerController, ListState<Employee>>(
      EmployeesPickerController.new,
    );

class EmployeesListController extends PagedListController<Employee> {
  @override
  Future<PageResult<Employee>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(employeesRepositoryProvider).list(page: page, query: query);
}

class EmployeesPickerController extends EmployeesListController {
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'per_page': 100,
    'sort': 'first_name',
  };
}
