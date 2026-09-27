import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/data/page_result.dart';
import 'package:mobile/core/permissions/permission_scope.dart';
import 'package:mobile/features/holidays/data/api_holidays_repository.dart';
import 'package:mobile/features/holidays/domain/holiday.dart';
import 'package:mobile/features/holidays/domain/holidays_repository.dart';
import 'package:mobile/features/leave/data/api_leave_repository.dart';
import 'package:mobile/features/leave/domain/leave_balance.dart';
import 'package:mobile/features/leave/domain/leave_repository.dart';
import 'package:mobile/features/leave/domain/leave_request.dart';
import 'package:mobile/features/leave/domain/leave_type.dart';
import 'package:mobile/features/overtime/data/api_overtime_repository.dart';
import 'package:mobile/features/overtime/domain/overtime_repository.dart';
import 'package:mobile/features/overtime/domain/overtime_request.dart';
import 'package:mobile/features/timesheet/data/api_timesheets_repository.dart';
import 'package:mobile/features/timesheet/domain/timesheet.dart';
import 'package:mobile/features/timesheet/domain/timesheets_repository.dart';

import 'fakes.dart';
import 'phase4.dart';

export 'phase4.dart' show advance, useTallScreen, forbidden403, notFound404;

/// A [PageResult] over an in-memory list, for the resources whose lists the
/// controllers page over but whose pools are not [Scripted]'s own — a picker
/// reading leave types and a list showing leave requests are two different
/// collections behind the same screen.
PageResult<T> pageOf<T>(List<T> items, int page, {int pageSize = 15}) {
  final total = items.length;
  final last = total == 0 ? 1 : (total + pageSize - 1) ~/ pageSize;
  final start = (page - 1) * pageSize;
  final end = start + pageSize > total ? total : start + pageSize;

  return PageResult<T>(
    items: start >= total ? <T>[] : items.sublist(start, end),
    currentPage: page,
    lastPage: last,
    perPage: pageSize,
    total: total,
    hasNext: page < last,
  );
}

/// The leave repository, scripted.
///
/// Everything the four transition endpoints do is recorded rather than
/// modelled: a screen test is asking "did the button call `reject` with the
/// remarks the dialog collected?", and a double that simulated the state
/// machine would only be re-testing the server through Dart.
class ScriptedLeave extends Scripted<LeaveRequest> implements LeaveRepository {
  ScriptedLeave({super.items, required super.idOf, super.fallback});

  /// The leave types the picker reads from — a separate pool, because the
  /// form's options are not the list's rows. Named as a field rather than
  /// `types`, which the method of the same contract has already taken.
  List<LeaveType> leaveTypes = <LeaveType>[];
  List<LeaveBalance> balanceRows = <LeaveBalance>[];

  String? lastTransition;
  int? lastTransitionId;
  String? lastRemarks;
  int? lastApprovedMinutes;

  int? lastCertificateId;
  Uint8List? lastCertificateBytes;
  String? lastCertificateFilename;
  Object? actionError;
  int actionErrorAfter = 0;

  Future<LeaveRequest> _transition(String name, int id, String remarks) async {
    lastTransition = name;
    lastTransitionId = id;
    lastRemarks = remarks;

    final error = actionError;
    if (error != null && actionErrorAfter <= 0) {
      actionError = null;
      throw error;
    }
    if (error != null) actionErrorAfter--;

    return super.find(id);
  }

  @override
  Future<LeaveRequest> submit(int id, {String remarks = ''}) =>
      _transition('submit', id, remarks);

  @override
  Future<LeaveRequest> approve(int id, {String remarks = ''}) =>
      _transition('approve', id, remarks);

  @override
  Future<LeaveRequest> reject(int id, {String remarks = ''}) =>
      _transition('reject', id, remarks);

  @override
  Future<LeaveRequest> cancel(int id, {String remarks = ''}) =>
      _transition('cancel', id, remarks);

  @override
  Future<LeaveRequest> fileCertificate(
    int id,
    Uint8List bytes, {
    String filename = 'certificate.jpg',
  }) async {
    lastCertificateId = id;
    lastCertificateBytes = bytes;
    lastCertificateFilename = filename;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return super.find(id);
  }

  @override
  Future<Uint8List> certificate(int id) async =>
      Uint8List.fromList(<int>[1, 2, 3]);

  @override
  Future<PageResult<LeaveType>> types({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async => pageOf(leaveTypes, page);

  @override
  Future<PageResult<LeaveBalance>> balances({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async => pageOf(balanceRows, page);
}

class ScriptedHolidays extends Scripted<Holiday> implements HolidaysRepository {
  ScriptedHolidays({super.items, required super.idOf, super.fallback});
}

/// Timesheets have no `create`/`update` — only the derive, which the test
/// records so a screen can be asked "did pressing refresh actually ask?"
class ScriptedTimesheets extends Scripted<Timesheet>
    implements TimesheetsRepository {
  ScriptedTimesheets({super.items, required super.idOf});

  int generateCalls = 0;
  String? lastGenerateFrom;
  String? lastGenerateTo;
  Object? generateError;

  @override
  Future<void> generate({
    required String from,
    required String to,
    int? employeeId,
  }) async {
    generateCalls++;
    lastGenerateFrom = from;
    lastGenerateTo = to;

    final error = generateError;
    if (error != null) {
      generateError = null;
      throw error;
    }
  }
}

class ScriptedOvertime extends Scripted<OvertimeRequest>
    implements OvertimeRepository {
  ScriptedOvertime({super.items, required super.idOf, super.fallback});

  String? lastTransition;
  int? lastTransitionId;
  String? lastRemarks;
  int? lastApprovedMinutes;
  Object? actionError;

  Future<OvertimeRequest> _transition(
    String name,
    int id, {
    String remarks = '',
    int? approvedMinutes,
  }) async {
    lastTransition = name;
    lastTransitionId = id;
    lastRemarks = remarks;
    lastApprovedMinutes = approvedMinutes;

    final error = actionError;
    if (error != null) {
      actionError = null;
      throw error;
    }

    return super.find(id);
  }

  @override
  Future<OvertimeRequest> submit(int id, {String remarks = ''}) =>
      _transition('submit', id, remarks: remarks);

  @override
  Future<OvertimeRequest> approve(
    int id, {
    String remarks = '',
    int? approvedMinutes,
  }) => _transition(
    'approve',
    id,
    remarks: remarks,
    approvedMinutes: approvedMinutes,
  );

  @override
  Future<OvertimeRequest> reject(int id, {String remarks = ''}) =>
      _transition('reject', id, remarks: remarks);

  @override
  Future<OvertimeRequest> cancel(int id, {String remarks = ''}) =>
      _transition('cancel', id, remarks: remarks);
}

/// Wraps a screen in the providers Phase 6 needs: a permission scope with
/// exactly the permissions under test, plus any scripted repositories.
///
/// The override list is written out inside the `ProviderScope` literal for
/// the same reason it is in `scopedPhase4`: `Override` is a riverpod type
/// that `flutter_riverpod` does not re-export, so naming it would mean
/// importing a package this project does not declare.
Widget scopedPhase6({
  required Widget child,
  List<String> permissions = const <String>[],
  List<String> roles = const <String>['Employee'],
  ScriptedLeave? leave,
  ScriptedHolidays? holidays,
  ScriptedTimesheets? timesheets,
  ScriptedOvertime? overtime,
}) => ProviderScope(
  overrides: [
    permissionScopeProvider.overrideWithValue(
      PermissionScope(buildUser(permissions: permissions, roles: roles)),
    ),
    if (leave != null) leaveRepositoryProvider.overrideWithValue(leave),
    if (holidays != null)
      holidaysRepositoryProvider.overrideWithValue(holidays),
    if (timesheets != null)
      timesheetsRepositoryProvider.overrideWithValue(timesheets),
    if (overtime != null)
      overtimeRepositoryProvider.overrideWithValue(overtime),
  ],
  child: child,
);

/// A router with just enough of the app's real path table for the screen
/// under test to navigate the way it does in production.
///
/// The screen does not know it is under test — `context.push('/leave/1')`
/// runs either way, and a router that could not resolve it would throw and
/// fail the test for the wrong reason.
GoRouter phase6Router(String initialLocation, List<GoRoute> routes) =>
    GoRouter(initialLocation: initialLocation, routes: routes);

/// Asserts that a [Scripted] list was asked with `key` set to `value`, or —
/// when [value] is null — that the key was absent.
///
/// Absent and empty are different questions to the API, which is why the
/// default `setFilter(key, '')` has to *remove* the key. This assertion
/// exists so that regression cannot pass as "the same thing".
void expectQuery(Map<String, Object?>? query, String key, Object? value) {
  expect(query, isNotNull, reason: 'no query was recorded');

  if (value == null) {
    expect(
      query!.containsKey(key),
      isFalse,
      reason: 'expected `$key` to be absent, got `${query[key]}`',
    );
    return;
  }

  expect(query![key], value, reason: 'expected `$key` to be `$value`');
}
