import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_projects_repository.dart';
import '../domain/project.dart';

final projectsListProvider =
    NotifierProvider<ProjectsListController, ListState<Project>>(
      ProjectsListController.new,
    );

/// Project names for the site form and the employee form.
final projectsPickerProvider =
    NotifierProvider<ProjectsPickerController, ListState<Project>>(
      ProjectsPickerController.new,
    );

class ProjectsListController extends PagedListController<Project> {
  @override
  Future<PageResult<Project>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref.watch(projectsRepositoryProvider).list(page: page, query: query);
}

class ProjectsPickerController extends ProjectsListController {
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'per_page': 100,
    'sort': 'name',
  };
}
