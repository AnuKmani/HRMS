/// One attendance row, as `AttendanceResource` sends it.
///
/// Note what is absent: `check_in_selfie_path`. The API never emits a
/// storage path, so there is no field here to accidentally render, log or
/// put in an error report. The photograph is fetched only through
/// `GET /attendance/{id}/selfie`, and all this model knows is whether one
/// exists.
class AttendanceRecord {
  const AttendanceRecord({
    required this.id,
    required this.employeeId,
    required this.siteId,
    required this.attendanceDate,
    required this.status,
    required this.workingMinutes,
    required this.breakMinutes,
    required this.overtimeMinutes,
    required this.lateMinutes,
    required this.earlyDepartureMinutes,
    this.projectId,
    this.siteName,
    this.projectName,
    this.checkInAt,
    this.checkOutAt,
    this.checkInLatitude,
    this.checkInLongitude,
    this.checkInAccuracy,
    this.checkInDistance,
    this.checkOutLatitude,
    this.checkOutLongitude,
    this.checkOutAccuracy,
    this.checkOutDistance,
    this.hasSelfie = false,
    this.scheduledStartAt,
    this.scheduledEndAt,
    this.source,
  });

  final int id;
  final int employeeId;
  final int siteId;
  final String attendanceDate;
  final String status;

  final int workingMinutes;
  final int breakMinutes;
  final int overtimeMinutes;
  final int lateMinutes;
  final int earlyDepartureMinutes;

  final int? projectId;
  final String? siteName;
  final String? projectName;

  final String? checkInAt;
  final String? checkOutAt;

  final double? checkInLatitude;
  final double? checkInLongitude;
  final double? checkInAccuracy;
  final double? checkInDistance;
  final double? checkOutLatitude;
  final double? checkOutLongitude;
  final double? checkOutAccuracy;
  final double? checkOutDistance;

  final bool hasSelfie;

  final String? scheduledStartAt;
  final String? scheduledEndAt;
  final String? source;

  bool get isOpen => checkOutAt == null;

  /// `present`, `late`, `incomplete`, `missing_checkout`, `manually_adjusted`.
  ///
  /// Read as data rather than turned into colours here: the same five words
  /// are the API's vocabulary, and a screen that decided what `incomplete`
  /// meant differently from the backend would be the second opinion nobody
  /// asked for.
  String get statusLabel => switch (status) {
    'present' => 'Present',
    'late' => 'Late',
    'incomplete' => 'Left early',
    'missing_checkout' => 'No check-out recorded',
    'manually_adjusted' => 'Adjusted by HR',
    _ => status,
  };

  factory AttendanceRecord.fromJson(Map<String, dynamic> json) {
    final site = json['site'];
    final project = json['project'];

    return AttendanceRecord(
      id: _int(json['id']) ?? 0,
      employeeId: _int(json['employee_id']) ?? 0,
      siteId: _int(json['site_id']) ?? 0,
      attendanceDate: json['attendance_date'] as String? ?? '',
      status: json['status'] as String? ?? '',
      workingMinutes: _int(json['working_minutes']) ?? 0,
      breakMinutes: _int(json['break_minutes']) ?? 0,
      overtimeMinutes: _int(json['overtime_minutes']) ?? 0,
      lateMinutes: _int(json['late_minutes']) ?? 0,
      earlyDepartureMinutes: _int(json['early_departure_minutes']) ?? 0,
      projectId: _int(json['project_id']),
      siteName: site is Map ? site['name'] as String? : null,
      projectName: project is Map ? project['name'] as String? : null,
      checkInAt: json['check_in_at'] as String?,
      checkOutAt: json['check_out_at'] as String?,
      checkInLatitude: _decimal(json['check_in_latitude']),
      checkInLongitude: _decimal(json['check_in_longitude']),
      checkInAccuracy: _decimal(json['check_in_accuracy']),
      checkInDistance: _decimal(json['check_in_distance']),
      checkOutLatitude: _decimal(json['check_out_latitude']),
      checkOutLongitude: _decimal(json['check_out_longitude']),
      checkOutAccuracy: _decimal(json['check_out_accuracy']),
      checkOutDistance: _decimal(json['check_out_distance']),
      hasSelfie: json['has_selfie'] == true,
      scheduledStartAt: json['scheduled_start_at'] as String?,
      scheduledEndAt: json['scheduled_end_at'] as String?,
      source: json['source'] as String?,
    );
  }
}

/// One bounded site visit — the two points somebody deliberately recorded,
/// and nothing in between.
class SiteVisit {
  const SiteVisit({
    required this.id,
    required this.siteId,
    required this.startedAt,
    required this.purpose,
    required this.status,
    this.endedAt,
    this.durationMinutes,
    this.siteName,
    this.startDistance,
    this.endDistance,
  });

  final int id;
  final int siteId;
  final String startedAt;
  final String? endedAt;
  final int? durationMinutes;
  final String purpose;
  final String status;
  final String? siteName;
  final double? startDistance;
  final double? endDistance;

  bool get isOpen => endedAt == null;

  factory SiteVisit.fromJson(Map<String, dynamic> json) {
    final site = json['site'];

    return SiteVisit(
      id: _int(json['id']) ?? 0,
      siteId: _int(json['site_id']) ?? 0,
      startedAt: json['started_at'] as String? ?? '',
      endedAt: json['ended_at'] as String?,
      durationMinutes: _int(json['duration_minutes']),
      purpose: json['purpose'] as String? ?? '',
      status: json['status'] as String? ?? '',
      siteName: site is Map ? site['name'] as String? : null,
      startDistance: _decimal(json['start_distance']),
      endDistance: _decimal(json['end_distance']),
    );
  }
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}

double? _decimal(Object? value) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value);
  return null;
}
