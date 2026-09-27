/// One derived timesheet row — what attendance looked like when the period
/// was generated.
///
/// Matches `TimesheetResource`. The three minutes are carried *and* the two
/// hours, because the server already rounded (`round(x / 60, 2)`) and doing
/// the division here would mean every screen agreed on rounding except the
/// one that did not.
///
/// A timesheet is a snapshot, not a record anybody edits: there is no
/// write endpoint and no approval endpoint, and [status] is a fact about
/// the *day* (`open` — still running or missing a punch, `complete`,
/// `incomplete`) rather than a workflow state.
class Timesheet {
  const Timesheet({
    required this.id,
    required this.employeeId,
    this.employeeName,
    required this.timesheetDate,
    this.projectId,
    this.projectName,
    this.siteId,
    this.siteName,
    this.attendanceId,
    this.checkInAt,
    this.checkOutAt,
    required this.workingMinutes,
    required this.breakMinutes,
    required this.overtimeMinutes,
    required this.workingHours,
    required this.overtimeHours,
    required this.status,
    this.notes,
  });

  static const statusOpen = 'open';
  static const statusComplete = 'complete';
  static const statusIncomplete = 'incomplete';

  final int id;
  final int employeeId;
  final String? employeeName;
  final String timesheetDate;
  final int? projectId;
  final String? projectName;
  final int? siteId;
  final String? siteName;

  /// Null for a day derived without a matching attendance row — which is
  /// exactly what makes [attendanceId] worth keeping rather than a boolean.
  final int? attendanceId;
  final String? checkInAt;
  final String? checkOutAt;

  final int workingMinutes;
  final int breakMinutes;
  final int overtimeMinutes;
  final double workingHours;
  final double overtimeHours;

  final String status;
  final String? notes;

  bool get isComplete => status == statusComplete;

  bool get isOpen => status == statusOpen;

  bool get isIncomplete => status == statusIncomplete;

  /// The day, as it reads on a card — `Mon 28 Sep 2026` from a plain
  /// `2026-09-28`, without a date package to do it.
  String get dateLabel {
    if (timesheetDate.length < 10) return timesheetDate;

    const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
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

    final parts = timesheetDate.substring(0, 10).split('-');
    final year = int.tryParse(parts[0]);
    final month = int.tryParse(parts[1]);
    final day = int.tryParse(parts[2]);

    if (year == null || month == null || day == null) return timesheetDate;

    // Proleptic Gregorian, days from 1970-01-01 — enough to pick a weekday
    // without importing a calendar library for one label.
    final weekday = weekdays[(DateTime(year, month, day).weekday - 1) % 7];

    return '$weekday $day ${months[month - 1]} $year';
  }

  String get statusLabel => switch (status) {
    statusComplete => 'Complete',
    statusIncomplete => 'Incomplete',
    _ => 'Open',
  };

  String get summary =>
      '${workingHours.toStringAsFixed(2)} h worked'
      '${overtimeMinutes > 0 ? ' · ${overtimeHours.toStringAsFixed(2)} h extra' : ''}';

  factory Timesheet.fromJson(Map<String, dynamic> json) => Timesheet(
    id: _int(json['id']) ?? 0,
    employeeId: _int(json['employee_id']) ?? 0,
    employeeName: _nestedName(json['employee']),
    timesheetDate: json['timesheet_date'] as String? ?? '',
    projectId: _int(json['project_id']),
    projectName: _nestedName(json['project']),
    siteId: _int(json['site_id']),
    siteName: _nestedName(json['site']),
    attendanceId: _int(json['attendance_id']),
    checkInAt: json['check_in_at'] as String?,
    checkOutAt: json['check_out_at'] as String?,
    workingMinutes: _int(json['working_minutes']) ?? 0,
    breakMinutes: _int(json['break_minutes']) ?? 0,
    overtimeMinutes: _int(json['overtime_minutes']) ?? 0,
    workingHours: _double(json['working_hours']),
    overtimeHours: _double(json['overtime_hours']),
    status: json['status'] as String? ?? statusOpen,
    notes: json['notes'] as String?,
  );
}

String? _nestedName(Object? raw) {
  if (raw is Map<String, dynamic>) {
    final name = raw['name'];
    if (name is String && name.isNotEmpty) return name;
  }

  return null;
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}

double _double(Object? value) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0;
  return 0;
}
