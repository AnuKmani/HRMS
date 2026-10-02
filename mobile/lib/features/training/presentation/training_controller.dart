import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/page_result.dart';
import '../../../core/presentation/list_state.dart';
import '../data/api_training_repository.dart';
import '../domain/employee_training.dart';
import '../domain/training_program.dart';
import '../domain/training_type.dart';

/// The enrolments the list screen shows.
///
/// The filters are query parameters and not a Dart-side `where` over the
/// page, for the same reason the documents list does it this way: the server
/// owns both the row scope and the totals, and a list narrowed in the
/// browser would still be reporting the unfiltered `total` underneath it —
/// "12 of 40" would then be a number nobody could act on.
final trainingListProvider =
    NotifierProvider<TrainingListController, ListState<EmployeeTraining>>(
      TrainingListController.new,
    );

/// `GET /employee-training/expiring` — the report, kept apart from the list
/// above because it is a *different question with a different permission*:
/// "whose card is about to lapse, across everybody I may see?" rather than
/// "show me the courses". Two providers rather than one with a flag is the
/// difference between the two staying honest about their own totals.
final trainingExpiryProvider =
    NotifierProvider<TrainingExpiryController, ListState<EmployeeTraining>>(
      TrainingExpiryController.new,
    );

/// The whole catalogue, for the programs screen's own list.
final trainingProgramsProvider =
    NotifierProvider<TrainingProgramsController, ListState<TrainingProgram>>(
      TrainingProgramsController.new,
    );

/// The same catalogue as a picker for the enrol form.
///
/// Its own provider for the same reason every picker has one: a course
/// chosen in the form must not rewrite the filter the catalogue screen is
/// drawing.
final trainingProgramsPickerProvider =
    NotifierProvider<
      TrainingProgramsPickerController,
      ListState<TrainingProgram>
    >(TrainingProgramsPickerController.new);

/// Training types for the program form's picker — the whole vocabulary in
/// one request, which is why there is no page number to fetch.
final trainingTypesPickerProvider =
    NotifierProvider<TrainingTypesPickerController, ListState<TrainingType>>(
      TrainingTypesPickerController.new,
    );

class TrainingListController extends PagedListController<EmployeeTraining> {
  /// `certificate` is this screen's own one-value selector; `certified`,
  /// `expired` and `expiring_soon` are the three the API reads.
  ///
  /// Translated here rather than at the tap for two reasons: the key stays
  /// in `state.query` so the dropdown has a value to draw when it comes
  /// back, and one function has to be right rather than three call sites.
  /// A value is sent as `1` because `BuildsResourceLists::flagged()` reads
  /// `1`, `true`, `yes` — and clearing the choice removes the key rather
  /// than sending `false`, because "not filtering" and "filtering on false"
  /// are different questions to the server.
  @override
  Future<PageResult<EmployeeTraining>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) {
    final wire = Map<String, Object?>.of(query);
    final certificate = wire.remove('certificate');

    if (certificate is String && certificate.isNotEmpty) {
      wire[certificate] = '1';
    }

    return ref.watch(trainingRepositoryProvider).list(page: page, query: wire);
  }
}

class TrainingExpiryController extends PagedListController<EmployeeTraining> {
  /// `within` widens the window from "already gone" to "soon", in days. A
  /// base query rather than a filter the screen sets, because the report has
  /// one window and a screen that sent a different one would be reading rows
  /// the server had already scoped differently.
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{'within': 90};

  @override
  Future<PageResult<EmployeeTraining>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) =>
      ref.watch(trainingRepositoryProvider).expiring(page: page, query: query);
}

class TrainingProgramsController extends PagedListController<TrainingProgram> {
  @override
  Future<PageResult<TrainingProgram>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) =>
      ref.watch(trainingRepositoryProvider).programs(page: page, query: query);
}

class TrainingProgramsPickerController extends TrainingProgramsController {
  /// `status: active` because a form may not file under a retired course —
  /// the choice is filtered by the server too, so this is the picker
  /// declining to offer a door that would only come back as a 422. `per_page`
  /// asks for the whole catalogue so a picker never shows nine of twelve.
  @override
  Map<String, Object?> get baseQuery => const <String, Object?>{
    'per_page': 100,
    'status': 'active',
    'sort': 'name',
  };
}

class TrainingTypesPickerController extends PagedListController<TrainingType> {
  @override
  Future<PageResult<TrainingType>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) async {
    final rows = await ref
        .watch(trainingRepositoryProvider)
        .types(query: query);

    // One response, one page, and the picker's "load more" never fires: a
    // vocabulary of eight does not need a second page, and pretending it had
    // one would put a "Load more" tile under four rows.
    return PageResult<TrainingType>(
      items: rows,
      currentPage: 1,
      lastPage: 1,
      perPage: rows.length,
      total: rows.length,
      hasNext: false,
    );
  }
}
