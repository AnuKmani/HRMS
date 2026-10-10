import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_exception.dart';

/// A site visit a Project Manager planned for a field engineer.
class SiteVisitPlan {
  const SiteVisitPlan({
    required this.id,
    required this.visitDate,
    required this.status,
    required this.purpose,
    required this.projectName,
    required this.isMine,
    required this.assignedByMe,
    this.notes,
    this.siteName,
    this.employeeName,
    this.assignedByName,
    this.completedAt,
    this.completionNote,
  });

  final int id;

  /// `YYYY-MM-DD`.
  final String visitDate;

  /// planned / completed / missed / cancelled.
  final String status;
  final String purpose;
  final String? notes;
  final String projectName;
  final String? siteName;
  final String? employeeName;
  final String? assignedByName;
  final String? completedAt;
  final String? completionNote;

  /// Planned for the signed-in person.
  final bool isMine;

  /// Planned by the signed-in person.
  final bool assignedByMe;

  bool get isOpen => status == 'planned';

  String get place =>
      siteName == null ? projectName : '$siteName · $projectName';

  String get statusLabel => switch (status) {
    'planned' => 'Planned',
    'completed' => 'Completed',
    'missed' => 'Missed',
    'cancelled' => 'Cancelled',
    _ => status,
  };

  /// `Wed 15 Oct`.
  String get prettyDate {
    final d = DateTime.tryParse(visitDate);
    if (d == null) return visitDate;
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    return '${days[d.weekday - 1]} ${d.day} ${months[d.month - 1]}';
  }

  factory SiteVisitPlan.fromJson(Map<String, dynamic> json) => SiteVisitPlan(
    id: (json['id'] as num?)?.toInt() ?? 0,
    visitDate: json['visit_date'] as String? ?? '',
    status: json['status'] as String? ?? 'planned',
    purpose: json['purpose'] as String? ?? '',
    notes: json['notes'] as String?,
    projectName: json['project_name'] as String? ?? '',
    siteName: json['site_name'] as String?,
    employeeName: json['employee_name'] as String?,
    assignedByName: json['assigned_by_name'] as String?,
    completedAt: json['completed_at'] as String?,
    completionNote: json['completion_note'] as String?,
    isMine: json['is_mine'] == true,
    assignedByMe: json['assigned_by_me'] == true,
  );
}

class PlanAssignee {
  const PlanAssignee({required this.id, required this.name, this.role});

  final int id;
  final String name;
  final String? role;
}

abstract class SiteVisitPlansRepository {
  /// [scope] `mine` limits to plans for the signed-in person.
  Future<List<SiteVisitPlan>> list({String? scope, String? status});

  Future<List<SiteVisitPlan>> create(Map<String, Object?> body);

  Future<SiteVisitPlan> complete(int id, {String? note});

  Future<SiteVisitPlan> cancel(int id);

  Future<List<PlanAssignee>> assignees();
}

class ApiSiteVisitPlansRepository implements SiteVisitPlansRepository {
  ApiSiteVisitPlansRepository(this._client);

  final ApiClient _client;

  static const _path = '/site-visit-plans';

  List<SiteVisitPlan> _items(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    final items = data['items'];
    return [
      if (items is List)
        for (final row in items)
          if (row is Map<String, dynamic>) SiteVisitPlan.fromJson(row),
    ];
  }

  SiteVisitPlan _one(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    return SiteVisitPlan.fromJson(data);
  }

  @override
  Future<List<SiteVisitPlan>> list({String? scope, String? status}) async =>
      _items(
        (await _client.get(
          _path,
          query: <String, Object?>{'scope': ?scope, 'status': ?status},
        )).data,
      );

  @override
  Future<List<SiteVisitPlan>> create(Map<String, Object?> body) async =>
      _items((await _client.post(_path, body: body)).data);

  @override
  Future<SiteVisitPlan> complete(int id, {String? note}) async => _one(
    (await _client.post(
      '$_path/$id/complete',
      body: <String, Object?>{'note': ?note},
    )).data,
  );

  @override
  Future<SiteVisitPlan> cancel(int id) async =>
      _one((await _client.post('$_path/$id/cancel')).data);

  @override
  Future<List<PlanAssignee>> assignees() async {
    final data = (await _client.get('$_path/assignees')).data;
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    final items = data['items'];
    return [
      if (items is List)
        for (final row in items)
          if (row is Map && row['id'] is num)
            PlanAssignee(
              id: (row['id'] as num).toInt(),
              name: row['name'] as String? ?? '',
              role: row['role'] as String?,
            ),
    ];
  }
}

final siteVisitPlansRepositoryProvider = Provider<SiteVisitPlansRepository>(
  (ref) => ApiSiteVisitPlansRepository(ref.watch(apiClientProvider)),
);

/// Plans for the signed-in engineer that are still to do, soonest first.
final myUpcomingPlansProvider = FutureProvider.autoDispose<List<SiteVisitPlan>>(
  (ref) => ref
      .watch(siteVisitPlansRepositoryProvider)
      .list(scope: 'mine', status: 'upcoming'),
);
