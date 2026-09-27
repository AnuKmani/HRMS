/// One entry in `GET /api/v1/movement/today`.
///
/// Four kinds and no others — `check_in`, `site_visit_start`,
/// `site_visit_end`, `check_out`. There is deliberately no "position
/// updated" kind: nothing in this system ever recorded a point nobody
/// asked for, so the timeline has nothing of that shape to render.
class MovementEvent {
  const MovementEvent({
    required this.type,
    required this.at,
    required this.recordId,
    required this.label,
    this.siteId,
    this.siteName,
    this.projectId,
    this.projectName,
    this.status,
    this.purpose,
    this.durationMinutes,
    this.workingMinutes,
  });

  static const String checkIn = 'check_in';
  static const String checkOut = 'check_out';
  static const String visitStart = 'site_visit_start';
  static const String visitEnd = 'site_visit_end';

  final String type;
  final String at;
  final int recordId;
  final String label;

  final int? siteId;
  final String? siteName;
  final int? projectId;
  final String? projectName;
  final String? status;

  final String? purpose;
  final int? durationMinutes;
  final int? workingMinutes;

  bool get isArrival => type == checkIn;

  bool get isDeparture => type == checkOut;

  factory MovementEvent.fromJson(Map<String, dynamic> json) => MovementEvent(
    type: json['type'] as String? ?? '',
    at: json['at'] as String? ?? '',
    recordId: _int(json['record_id']) ?? 0,
    label: json['label'] as String? ?? '',
    siteId: _int(json['site_id']),
    siteName: json['site_name'] as String?,
    projectId: _int(json['project_id']),
    projectName: json['project_name'] as String?,
    status: json['status'] as String?,
    purpose: json['purpose'] as String?,
    durationMinutes: _int(json['duration_minutes']),
    workingMinutes: _int(json['working_minutes']),
  );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
