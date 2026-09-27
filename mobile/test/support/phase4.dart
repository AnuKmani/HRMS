import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/features/departments/data/api_departments_repository.dart';
import 'package:mobile/features/departments/domain/department.dart';
import 'package:mobile/features/departments/domain/department_repository.dart';
import 'package:mobile/features/designations/data/api_designations_repository.dart';
import 'package:mobile/features/designations/domain/designation.dart';
import 'package:mobile/features/designations/domain/designation_repository.dart';
import 'package:mobile/features/employees/data/api_employees_repository.dart';
import 'package:mobile/features/employees/domain/employee.dart';
import 'package:mobile/features/employees/domain/employees_repository.dart';
import 'package:mobile/features/projects/data/api_projects_repository.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/projects/domain/projects_repository.dart';
import 'package:mobile/features/sites/data/api_sites_repository.dart';
import 'package:mobile/features/sites/domain/site.dart';
import 'package:mobile/features/sites/domain/sites_repository.dart';

import 'fakes.dart';

/// Number of frames given to a request that was kicked off by a screen.
///
/// Counted rather than `pumpAndSettle`: every list screen draws a spinner
/// while its first page is in flight, and settling would spin on that
/// animation until the test timed out on something working correctly.
Future<void> advance(WidgetTester tester, {int frames = 6}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

/// Gives a test a surface tall enough to hold a whole screen.
///
/// The employee form is eighteen fields; on the default 800×600 test surface
/// its save button sits two thousand pixels below the fold, and a `tap()`
/// that misses does not fail on the tap — it warns, the handler never runs,
/// and the assertion afterwards fails for a reason unrelated to whatever was
/// being tested. Resizing the window to a full-height phone keeps these tests
/// about behaviour rather than about geometry.
void useTallScreen(WidgetTester tester) {
  addTearDown(tester.view.reset);
  tester.view.physicalSize = const Size(800, 3200);
  tester.view.devicePixelRatio = 1.0;
}

const ApiException forbidden403 = ApiException(
  statusCode: 403,
  message: 'This action is forbidden.',
);

const ApiException notFound404 = ApiException(
  statusCode: 404,
  message: 'The requested record was not found.',
);

const ApiException unreachable = ApiException(
  statusCode: 0,
  message: 'Could not reach the server. Check your connection and try again.',
);

/// A repository double that does what the test scripted and remembers what
/// it was asked.
///
/// One implementation for all five resources: their contracts are the same
/// five verbs, and writing five near-identical doubles would mean five places
/// for the recording of `lastBody` to drift out of step with the form that
/// reads it.
class Scripted<T> {
  Scripted({
    required this.idOf,
    List<T>? items,
    this.pageSize = 15,
    this.fallback,
  }) : items = List<T>.of(items ?? <T>[]);

  /// How this resource's identity is read, for `find`/`update`/`remove`.
  final int Function(T item) idOf;

  /// The pool the fake pages over.
  final List<T> items;

  final int pageSize;

  /// Used to answer `create`/`update` when [items] is empty and no [onSave]
  /// was scripted — a form test that only cares about the *request* should
  /// not have to also stage a response.
  final T? fallback;

  Object? listError;
  Object? findError;
  Object? saveError;
  Object? removeError;

  /// When set, `list` parks on this future until the test completes it —
  /// the only honest way to assert on the *loading* state, since a double
  /// that answers immediately never renders one.
  Completer<void>? holdList;

  /// Replaces [items] for a single `list` — the way to script "the second
  /// page comes back different" or "this filter narrows the result".
  List<T> Function(Map<String, Object?> query)? filter;

  /// Builds the model `create`/`update` should answer with.
  T Function(Map<String, Object?> body)? onSave;

  int listCalls = 0;
  int findCalls = 0;
  int createCalls = 0;
  int updateCalls = 0;
  int removeCalls = 0;

  int? lastPage;
  int? lastId;
  Map<String, Object?>? lastQuery;
  Map<String, Object?>? lastBody;

  Future<PageResult<T>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    listCalls++;
    lastPage = page;
    lastQuery = query;

    final error = listError;
    if (error != null) {
      listError = null;
      throw error;
    }

    final held = holdList;
    if (held != null) await held.future;

    final visible = filter?.call(query) ?? items;
    final total = visible.length;
    final last = total == 0 ? 1 : (total + pageSize - 1) ~/ pageSize;
    final start = (page - 1) * pageSize;
    final end = start + pageSize > total ? total : start + pageSize;

    return PageResult<T>(
      items: start >= total ? <T>[] : visible.sublist(start, end),
      currentPage: page,
      lastPage: last,
      perPage: pageSize,
      total: total,
      hasNext: page < last,
    );
  }

  Future<T> find(int id) async {
    findCalls++;
    lastId = id;

    final error = findError;
    if (error != null) {
      findError = null;
      throw error;
    }

    for (final item in items) {
      if (idOf(item) == id) return item;
    }

    throw notFound404;
  }

  Future<T> create(Map<String, Object?> body) => _save(body, isUpdate: false);

  Future<T> update(int id, Map<String, Object?> body) {
    lastId = id;
    return _save(body, isUpdate: true);
  }

  Future<T> _save(Map<String, Object?> body, {required bool isUpdate}) async {
    if (isUpdate) {
      updateCalls++;
    } else {
      createCalls++;
    }
    lastBody = body;

    final error = saveError;
    if (error != null) {
      saveError = null;
      throw error;
    }

    final custom = onSave;
    if (custom != null) return custom(body);

    if (items.isNotEmpty) return items.first;
    if (fallback != null) return fallback as T;

    throw unexpectedShapeException;
  }

  Future<void> remove(int id) async {
    removeCalls++;
    lastId = id;

    final error = removeError;
    if (error != null) {
      removeError = null;
      throw error;
    }
  }
}

class ScriptedEmployees extends Scripted<Employee>
    implements EmployeesRepository {
  ScriptedEmployees({super.items, super.pageSize, required super.idOf, super.fallback});
}

class ScriptedDepartments extends Scripted<Department>
    implements DepartmentsRepository {
  ScriptedDepartments({super.items, super.pageSize, required super.idOf, super.fallback});
}

class ScriptedDesignations extends Scripted<Designation>
    implements DesignationsRepository {
  ScriptedDesignations({super.items, super.pageSize, required super.idOf, super.fallback});
}

class ScriptedProjects extends Scripted<Project>
    implements ProjectsRepository {
  ScriptedProjects({super.items, super.pageSize, required super.idOf, super.fallback});
}

class ScriptedSites extends Scripted<Site> implements SitesRepository {
  ScriptedSites({super.items, super.pageSize, required super.idOf, super.fallback});
}

/// Wraps a screen in the providers Phase 4 needs: a permission scope with
/// exactly the permissions under test, plus any scripted repositories.
///
/// The override list is written out inside the `ProviderScope` literal on
/// purpose. `Override` is a riverpod type that `flutter_riverpod` does not
/// re-export, so naming it would mean importing a package this project does
/// not declare — inference from the literal's `overrides:` parameter is what
/// lets every override here be spelled without it.
Widget scopedPhase4({
  required Widget child,
  List<String> permissions = const <String>[],
  List<String> roles = const <String>['Employee'],
  ScriptedEmployees? employees,
  ScriptedDepartments? departments,
  ScriptedDesignations? designations,
  ScriptedProjects? projects,
  ScriptedSites? sites,
}) =>
    ProviderScope(
      overrides: [
        permissionScopeProvider.overrideWithValue(
          PermissionScope(
            buildUser(permissions: permissions, roles: roles),
          ),
        ),
        if (employees != null)
          employeesRepositoryProvider.overrideWithValue(employees),
        if (departments != null)
          departmentsRepositoryProvider.overrideWithValue(departments),
        if (designations != null)
          designationsRepositoryProvider.overrideWithValue(designations),
        if (projects != null)
          projectsRepositoryProvider.overrideWithValue(projects),
        if (sites != null) sitesRepositoryProvider.overrideWithValue(sites),
      ],
      child: child,
    );
