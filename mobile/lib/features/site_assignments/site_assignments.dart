import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/data/page_result.dart';
import '../../core/network/api_client.dart';
import '../../core/network/api_exception.dart';
import '../../core/presentation/list_state.dart';

/// One posting of one employee to one site — `EmployeeSiteAssignmentResource`.
///
/// This is what lets a person check in at a site at all: the attendance API
/// refuses a check-in at a site the employee has no active assignment to.
class SiteAssignment {
  const SiteAssignment({
    required this.id,
    required this.employeeId,
    required this.siteId,
    required this.projectId,
    required this.assignmentType,
    required this.startDate,
    required this.status,
    this.endDate,
    this.employeeName,
    this.employeeCode,
    this.siteName,
    this.projectName,
  });

  static const typePrimary = 'primary';
  static const typeTemporary = 'temporary';
  static const typeAdditional = 'additional';

  static const statusActive = 'active';
  static const statusEnded = 'ended';
  static const statusCancelled = 'cancelled';

  final int id;
  final int employeeId;
  final int siteId;
  final int projectId;
  final String assignmentType;
  final String startDate;
  final String? endDate;
  final String status;

  final String? employeeName;
  final String? employeeCode;
  final String? siteName;
  final String? projectName;

  bool get isActive => status == statusActive;

  String get typeLabel => switch (assignmentType) {
    typePrimary => 'Primary',
    typeTemporary => 'Temporary',
    typeAdditional => 'Additional',
    _ => assignmentType,
  };

  String get statusLabel => switch (status) {
    statusActive => 'Active',
    statusEnded => 'Ended',
    statusCancelled => 'Cancelled',
    _ => status,
  };

  factory SiteAssignment.fromJson(Map<String, dynamic> json) {
    final employee = json['employee'];
    final site = json['site'];
    final project = json['project'];

    return SiteAssignment(
      id: json['id'] as int,
      employeeId: json['employee_id'] as int? ?? 0,
      siteId: json['site_id'] as int? ?? 0,
      projectId: json['project_id'] as int? ?? 0,
      assignmentType: json['assignment_type'] as String? ?? '',
      startDate: json['start_date'] as String? ?? '',
      endDate: json['end_date'] as String?,
      status: json['status'] as String? ?? '',
      employeeName: employee is Map ? employee['full_name'] as String? : null,
      employeeCode: employee is Map
          ? employee['employee_code'] as String?
          : null,
      siteName: site is Map ? site['name'] as String? : null,
      projectName: project is Map ? project['name'] as String? : null,
    );
  }
}

abstract class SiteAssignmentsRepository {
  Future<PageResult<SiteAssignment>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<SiteAssignment> create(Map<String, Object?> body);

  /// Closing a posting is a status change, not a deletion: the row stays as
  /// the record of who worked where.
  Future<SiteAssignment> end(int id, {required String endDate});
}

final siteAssignmentsRepositoryProvider = Provider<SiteAssignmentsRepository>(
  (ref) => ApiSiteAssignmentsRepository(ref.watch(apiClientProvider)),
);

class ApiSiteAssignmentsRepository implements SiteAssignmentsRepository {
  ApiSiteAssignmentsRepository(this._client);

  static const _path = '/employee-site-assignments';

  final ApiClient _client;

  @override
  Future<PageResult<SiteAssignment>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  }) async {
    final envelope = await _client.get(
      _path,
      query: <String, Object?>{...query, 'page': page},
    );

    return PageResult<SiteAssignment>.fromEnvelope(
      envelope,
      SiteAssignment.fromJson,
    );
  }

  @override
  Future<SiteAssignment> create(Map<String, Object?> body) async =>
      _one((await _client.post(_path, body: body)).data);

  @override
  Future<SiteAssignment> end(int id, {required String endDate}) async => _one(
    (await _client.put(
      '$_path/$id',
      body: <String, Object?>{
        'status': SiteAssignment.statusEnded,
        'end_date': endDate,
      },
    )).data,
  );

  SiteAssignment _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    return SiteAssignment.fromJson(data);
  }
}

final siteAssignmentsListProvider =
    NotifierProvider<SiteAssignmentsListController, ListState<SiteAssignment>>(
      SiteAssignmentsListController.new,
    );

class SiteAssignmentsListController
    extends PagedListController<SiteAssignment> {
  /// Opens on who is posted where *now*; ended postings are one filter away.
  @override
  Map<String, Object?> get initialQuery => const <String, Object?>{
    'status': SiteAssignment.statusActive,
  };

  @override
  Future<PageResult<SiteAssignment>> fetch({
    required int page,
    required Map<String, Object?> query,
  }) => ref
      .watch(siteAssignmentsRepositoryProvider)
      .list(page: page, query: query);
}
