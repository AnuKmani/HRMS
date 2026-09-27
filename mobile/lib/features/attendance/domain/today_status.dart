import 'assigned_site.dart';
import 'attendance_record.dart';

/// What the server says about today, for this person, right now.
///
/// This is the whole contract of `GET /api/v1/attendance/today`. The
/// booleans at the bottom are decided by the backend — `can_check_in`,
/// `can_check_out` and the rest are the answer to "what may I do", and a
/// screen that recomputed them would eventually disagree with the endpoint
/// that has to authorise the tap.
class TodayStatus {
  const TodayStatus({
    required this.date,
    required this.serverTime,
    required this.employeeId,
    required this.checkedIn,
    required this.checkedOut,
    required this.canCheckIn,
    required this.canCheckOut,
    required this.canStartSiteVisit,
    required this.sites,
    required this.workingMinutes,
    required this.lateMinutes,
    this.attendance,
    this.openAttendance,
    this.site,
    this.shift,
    this.maxGpsAccuracyMetres = 100,
  });

  final String date;
  final String serverTime;
  final int employeeId;

  final AttendanceRecord? attendance;

  /// The row awaiting a check-out: today's while it is open, an earlier
  /// day's if today has not begun. Null once the day is properly closed.
  final AttendanceRecord? openAttendance;

  final bool checkedIn;
  final bool checkedOut;

  final AssignedSite? site;
  final List<AssignedSite> sites;

  final ShiftSummary? shift;

  final int workingMinutes;
  final int lateMinutes;

  final bool canCheckIn;
  final bool canCheckOut;
  final bool canStartSiteVisit;

  /// `hrms.attendance.max_gps_accuracy_metres`, so the phone's advisory
  /// check uses the same ceiling the server will apply.
  final double maxGpsAccuracyMetres;

  /// The site the buttons act on, if any.
  AssignedSite? siteById(int id) {
    for (final site in sites) {
      if (site.id == id) return site;
    }
    return null;
  }

  factory TodayStatus.fromJson(Map<String, dynamic> json) {
    return TodayStatus(
      date: json['date'] as String? ?? '',
      serverTime: json['server_time'] as String? ?? '',
      employeeId: _int(json['employee_id']) ?? 0,
      attendance: _one(json['attendance']),
      openAttendance: _one(json['open_attendance']),
      checkedIn: json['checked_in'] == true,
      checkedOut: json['checked_out'] == true,
      site: _site(json['site']),
      sites: _sites(json['sites']),
      shift: json['shift'] is Map
          ? ShiftSummary.fromJson(json['shift']! as Map<String, dynamic>)
          : null,
      workingMinutes: _int(json['working_minutes']) ?? 0,
      lateMinutes: _int(json['late_minutes']) ?? 0,
      canCheckIn: json['can_check_in'] == true,
      canCheckOut: json['can_check_out'] == true,
      canStartSiteVisit: json['can_start_site_visit'] == true,
      maxGpsAccuracyMetres: _decimal(json['max_gps_accuracy_metres']) ?? 100,
    );
  }

  static AttendanceRecord? _one(Object? value) =>
      value is Map<String, dynamic> ? AttendanceRecord.fromJson(value) : null;

  static AssignedSite? _site(Object? value) =>
      value is Map<String, dynamic> ? AssignedSite.fromJson(value) : null;

  static List<AssignedSite> _sites(Object? value) {
    if (value is! List) return const <AssignedSite>[];

    return [
      for (final row in value)
        if (row is Map<String, dynamic>) AssignedSite.fromJson(row),
    ];
  }
}

/// The schedule the row was measured against — the same seven figures the
/// backend resolved from settings, never a hard-coded 09:00.
class ShiftSummary {
  const ShiftSummary({
    required this.startsAt,
    required this.endsAt,
    required this.graceMinutes,
    required this.breakMinutes,
    required this.minimumWorkingMinutes,
    required this.overtimeThresholdMinutes,
    required this.crossesMidnight,
    this.shiftId,
    this.name,
  });

  final int? shiftId;
  final String? name;
  final String startsAt;
  final String endsAt;
  final int graceMinutes;
  final int breakMinutes;
  final int minimumWorkingMinutes;
  final int overtimeThresholdMinutes;
  final bool crossesMidnight;

  factory ShiftSummary.fromJson(Map<String, dynamic> json) => ShiftSummary(
    shiftId: _int(json['shift_id']),
    name: json['name'] as String?,
    startsAt: json['starts_at'] as String? ?? '',
    endsAt: json['ends_at'] as String? ?? '',
    graceMinutes: _int(json['grace_minutes']) ?? 0,
    breakMinutes: _int(json['break_minutes']) ?? 0,
    minimumWorkingMinutes: _int(json['minimum_working_minutes']) ?? 0,
    overtimeThresholdMinutes: _int(json['overtime_threshold_minutes']) ?? 0,
    crossesMidnight: json['crosses_midnight'] == true,
  );
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
