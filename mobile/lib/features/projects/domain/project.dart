/// A project.
///
/// Matches `ProjectResource`. The two `*_count` values are only in the
/// payload when the endpoint asked for them, so they stay nullable: an
/// absent count is not a count of zero, and a list that printed `0 sites`
/// for a project whose site count was never requested would be inventing it.
class Project {
  const Project({
    required this.id,
    required this.name,
    required this.code,
    this.client,
    this.description,
    this.location,
    this.projectManagerId,
    this.projectManagerName,
    this.startDate,
    this.endDate,
    required this.status,
    this.sitesCount,
    this.employeesCount,
    this.createdAt,
  });

  final int id;
  final String name;
  final String code;
  final String? client;
  final String? description;
  final String? location;

  final int? projectManagerId;

  /// `full_name` from the embedded `EmployeeResource`, when it was loaded.
  final String? projectManagerName;

  final String? startDate;
  final String? endDate;

  /// `planned`, `active`, `on_hold`, `completed` or `cancelled`.
  final String status;

  final int? sitesCount;
  final int? employeesCount;
  final String? createdAt;

  bool get isOngoing => status == 'active' || status == 'on_hold';

  /// What a list row shows underneath the title.
  String get summary {
    final parts = <String>[code];

    if (client != null && client!.trim().isNotEmpty) parts.add(client!);

    if (projectManagerName != null) parts.add(projectManagerName!);

    return parts.join(' · ');
  }

  /// A compact "dates" line for the detail screen, or null when neither end
  /// has been decided yet.
  String? get dateRange {
    if (startDate == null && endDate == null) return null;

    if (startDate != null && endDate != null) {
      return '$startDate → $endDate';
    }

    return startDate != null ? 'From $startDate' : 'Until $endDate';
  }

  factory Project.fromJson(Map<String, dynamic> json) => Project(
        id: _int(json['id']) ?? 0,
        name: json['name'] as String? ?? '',
        code: json['code'] as String? ?? '',
        client: json['client'] as String?,
        description: json['description'] as String?,
        location: json['location'] as String?,
        projectManagerId: _int(json['project_manager_id']),
        projectManagerName: _nested(json['project_manager'], 'full_name'),
        startDate: json['start_date'] as String?,
        endDate: json['end_date'] as String?,
        status: json['status'] as String? ?? 'planned',
        sitesCount: _int(json['sites_count']),
        employeesCount: _int(json['employees_count']),
        createdAt: json['created_at'] as String?,
      );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}

String? _nested(Object? value, String key) {
  if (value is! Map) return null;

  final read = value[key];
  return read is String ? read : null;
}
