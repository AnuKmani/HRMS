import 'project_delivery.dart';

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
    this.upcomingEvents = const <ProjectEvent>[],
    this.events = const <ProjectEvent>[],
    this.mainContractor,
    this.progressPercent = 0,
    this.expectedPercent,
    this.behindSchedule = false,
    this.createdByName,
    this.canEdit,
    this.stages = const <ProjectStage>[],
    this.subcontractors = const <Subcontractor>[],
    this.team = const <TeamMember>[],
    this.milestones = const <PaymentMilestone>[],
    this.documents = const <ProjectDocumentFile>[],
  });

  final String? mainContractor;
  final double progressPercent;

  /// Where progress should be by today from the dates; null without dates.
  final double? expectedPercent;
  final bool behindSchedule;

  /// The Project Manager who added it — the only one who edits it.
  final String? createdByName;

  /// From the server; null when an older server did not say.
  final bool? canEdit;

  final List<ProjectStage> stages;
  final List<Subcontractor> subcontractors;
  final List<TeamMember> team;
  final List<PaymentMilestone> milestones;
  final List<ProjectDocumentFile> documents;

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

  /// `planned`, `active` (shown as Ongoing), `handing_over`, `on_hold`,
  /// `completed` or `cancelled`.
  final String status;

  /// DCD / Civil Defence / handover dates from today on, soonest first.
  final List<ProjectEvent> upcomingEvents;

  /// Every dated event, history included — only on a single project.
  final List<ProjectEvent> events;

  String get statusLabel => projectStatusLabel(status);

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
    upcomingEvents: ProjectEvent.listFrom(json['upcoming_events']),
    events: ProjectEvent.listFrom(json['events']),
    mainContractor: json['main_contractor'] as String?,
    progressPercent: (json['progress_percent'] as num?)?.toDouble() ?? 0,
    expectedPercent: (json['expected_percent'] as num?)?.toDouble(),
    behindSchedule: json['behind_schedule'] == true,
    createdByName: json['created_by_name'] as String?,
    canEdit: json['can_edit'] is bool ? json['can_edit'] as bool : null,
    stages: listOf(json['stages'], ProjectStage.fromJson),
    subcontractors: listOf(json['subcontractors'], Subcontractor.fromJson),
    team: listOf(json['team'], TeamMember.fromJson),
    milestones: listOf(json['payment_milestones'], PaymentMilestone.fromJson),
    documents: listOf(json['documents'], ProjectDocumentFile.fromJson),
  );
}

/// The statuses HR reads on the project list, in the order a project moves
/// through them.
const projectStatuses = <(String, String)>[
  ('planned', 'Planned'),
  ('active', 'Ongoing'),
  ('handing_over', 'Handing over'),
  ('completed', 'Completed'),
  ('on_hold', 'On hold'),
  ('cancelled', 'Cancelled'),
];

String projectStatusLabel(String status) {
  for (final (value, label) in projectStatuses) {
    if (value == status) return label;
  }
  return status.replaceAll('_', ' ');
}

/// A dated milestone on a project: DCD inspection, Civil Defence
/// inspection, handover…
class ProjectEvent {
  const ProjectEvent({
    required this.id,
    required this.type,
    required this.label,
    required this.eventDate,
    this.notes,
  });

  final int id;
  final String type;
  final String label;

  /// `YYYY-MM-DD`.
  final String eventDate;
  final String? notes;

  static const types = <(String, String)>[
    ('dcd_inspection', 'DCD inspection'),
    ('civil_defence', 'Civil Defence inspection'),
    ('handover', 'Handover'),
    ('other', 'Other'),
  ];

  /// `10 Oct 2026`.
  String get prettyDate {
    final date = DateTime.tryParse(eventDate);
    if (date == null) return eventDate;
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
    return '${date.day} ${months[date.month - 1]} ${date.year}';
  }

  bool isPast(DateTime today) {
    final date = DateTime.tryParse(eventDate);
    if (date == null) return false;
    return date.isBefore(DateTime(today.year, today.month, today.day));
  }

  factory ProjectEvent.fromJson(Map<String, dynamic> json) => ProjectEvent(
    id: _int(json['id']) ?? 0,
    type: json['type'] as String? ?? 'other',
    label: json['label'] as String? ?? '',
    eventDate: json['event_date'] as String? ?? '',
    notes: json['notes'] as String?,
  );

  static List<ProjectEvent> listFrom(Object? value) => [
    if (value is List)
      for (final row in value)
        if (row is Map<String, dynamic>) ProjectEvent.fromJson(row),
  ];
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
