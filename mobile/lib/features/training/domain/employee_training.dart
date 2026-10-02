import 'package:flutter/material.dart';

import '../../../core/presentation/status_chip.dart';
import 'training_program.dart';

/// One person's enrolment in one training program, and the certificate it
/// produced.
///
/// Mirrors `EmployeeTrainingResource`. Three pairs in here are never allowed
/// to stand in for each other, and they are the same three an employment
/// document's payload is careful about — deliberately, because the questions
/// are the same:
///
///  - [status] and [expiryState] — the stored decision (`completed`,
///    `expired`, `failed`) and the date arithmetic computed *right now* on
///    the server. The client renders both and derives neither: a `completed`
///    row whose card expired yesterday reports `expired` whether or not the
///    scheduler has caught up, so a lagging cron cannot make this app tell a
///    lie.
///
///  - [hasCertificate] and [certificateFileUrl] — whether there is one, and
///    the single API route that will serve it behind the policy that already
///    governs the row. **There is no `certificate_path` anywhere in this
///    payload and none in this class**, for the reason `EmployeeDocument`
///    carries no `path`: a stored location is exactly the detail that should
///    not leave the server.
///
///  - [daysUntilExpiry] and [certificateExpiryDate] — a signed count and the
///    date it was counted from. The sign carries the direction, so no screen
///    has to pair the number with its own comparison to know which way it
///    points.
class EmployeeTraining {
  const EmployeeTraining({
    required this.id,
    required this.employeeId,
    this.employeeName,
    required this.trainingProgramId,
    this.trainingProgram,
    this.enrollmentDate,
    this.trainingDate,
    this.completionDate,
    this.trainer,
    this.programProvider,
    required this.status,
    this.result,
    this.remarks,
    this.certificateNumber,
    this.certificateIssueDate,
    this.certificateExpiryDate,
    required this.hasCertificate,
    this.certificateOriginalName,
    this.certificateMimeType,
    this.certificateSize,
    this.certificateFileUrl,
    required this.expiryState,
    required this.warningDays,
    this.daysUntilExpiry,
    this.expiryNotifiedAt,
    this.createdBy,
    required this.isEditable,
    required this.isTerminal,
    required this.isCompletable,
    this.createdAt,
  });

  static const statusEnrolled = 'enrolled';
  static const statusScheduled = 'scheduled';
  static const statusInProgress = 'in_progress';
  static const statusCompleted = 'completed';
  static const statusFailed = 'failed';
  static const statusCancelled = 'cancelled';
  static const statusExpired = 'expired';

  static const statuses = [
    statusEnrolled,
    statusScheduled,
    statusInProgress,
    statusCompleted,
    statusFailed,
    statusCancelled,
    statusExpired,
  ];

  static const expiryNone = 'none';
  static const expiryValid = 'valid';
  static const expirySoon = 'expiring_soon';
  static const expiryExpired = 'expired';

  final int id;
  final int employeeId;
  final String? employeeName;

  final int trainingProgramId;
  final TrainingProgram? trainingProgram;

  final String? enrollmentDate;
  final String? trainingDate;
  final String? completionDate;

  /// The *effective* trainer: the enrolment's own, or the program's default
  /// when it named none. Resolved by the server so correcting a program's
  /// provider updates every cohort that did not name one of its own.
  final String? trainer;
  final String? programProvider;

  final String status;
  final String? result;
  final String? remarks;

  final String? certificateNumber;
  final String? certificateIssueDate;
  final String? certificateExpiryDate;

  final bool hasCertificate;
  final String? certificateOriginalName;
  final String? certificateMimeType;
  final int? certificateSize;
  final String? certificateFileUrl;

  /// Server-computed from the date *right now* — rendered, never derived.
  final String expiryState;
  final int warningDays;
  final int? daysUntilExpiry;
  final String? expiryNotifiedAt;

  final int? createdBy;

  /// Record state, not reader permission: whether *you* may act is
  /// PermissionScope's answer, asked when you try.
  final bool isEditable;
  final bool isTerminal;
  final bool isCompletable;

  final String? createdAt;

  String get programName => trainingProgram?.name ?? '';

  String get programCode => trainingProgram?.code ?? '';

  /// The *kind* of course — Safety induction, Working at heights — read off
  /// the program rather than carried as its own field, so correcting a
  /// program's type updates every cohort filed under it.
  String get typeName => trainingProgram?.typeName ?? '';

  bool get isCompleted => status == statusCompleted;

  /// Whether the course this enrolment belongs to promises a card at all.
  ///
  /// Read off the program rather than carried on the enrolment, because it
  /// is a fact about the *course*: a completion form needs it to know that a
  /// file is mandatory rather than optional, and asking the catalogue for it
  /// would be a second request for something already in the payload.
  bool get certificateRequired => trainingProgram?.certificateRequired ?? false;

  String get statusText => switch (status) {
    statusEnrolled => 'Enrolled',
    statusScheduled => 'Scheduled',
    statusInProgress => 'In progress',
    statusCompleted => 'Completed',
    statusFailed => 'Failed',
    statusCancelled => 'Cancelled',
    // The *certificate* lapsed, not the training: a row that says this is
    // still proof the person sat the course, which is why it is its own
    // word rather than a second spelling of `failed`.
    statusExpired => 'Certificate expired',
    _ => status,
  };

  StatusTone get statusTone => switch (status) {
    statusCompleted => StatusTone.positive,
    statusFailed => StatusTone.negative,
    statusCancelled => StatusTone.neutral,
    statusExpired => StatusTone.warning,
    statusInProgress => StatusTone.info,
    _ => StatusTone.neutral,
  };

  /// The expiry chip's words.
  ///
  /// **Always words, never a colour alone** — every one of the four states
  /// reads differently, so a colour-blind reader, or a screenshot pasted
  /// into an email, carries the same information as the tinted chip beside
  /// it. `daysUntilExpiry` is signed, so the direction is in the number
  /// rather than in a comparison the screen would have to repeat.
  String get expiryText => switch (expiryState) {
    expiryNone => 'No expiry date',
    expiryExpired => _absoluteDays(isPast: true),
    expirySoon => _absoluteDays(isPast: false),
    expiryValid => _absoluteDays(isPast: false),
    _ =>
      certificateExpiryDate == null
          ? 'No expiry date'
          : 'Expires ${certificateExpiryDate!}',
  };

  StatusTone get expiryTone => switch (expiryState) {
    expiryExpired => StatusTone.negative,
    expirySoon => StatusTone.warning,
    expiryValid => StatusTone.positive,
    _ => StatusTone.neutral,
  };

  IconData get expiryIcon => switch (expiryState) {
    expiryExpired => Icons.event_busy_outlined,
    expirySoon => Icons.schedule_outlined,
    expiryValid => Icons.event_available_outlined,
    _ => Icons.event_outlined,
  };

  /// "Expires in 5 days" / "Expired 12 days ago" / "Expires today".
  ///
  /// Singular, plural and today are all spelled out because this is the one
  /// label a person acts on: "in 1 days" reads as a bug and makes the reader
  /// distrust the number next to it.
  String _absoluteDays({required bool isPast}) {
    final days = daysUntilExpiry;

    if (days == null) {
      final date = certificateExpiryDate;
      if (date == null) return 'No expiry date';
      return isPast ? 'Expired $date' : 'Expires $date';
    }

    final magnitude = days.abs();

    if (magnitude == 0) return isPast ? 'Expired today' : 'Expires today';
    if (magnitude == 1) {
      return isPast ? 'Expired yesterday' : 'Expires tomorrow';
    }

    return isPast
        ? 'Expired $magnitude days ago'
        : 'Expires in $magnitude days';
  }

  /// "245 KB", "3.1 MB" — the size a person recognises, not raw bytes.
  String get sizeLabel {
    final bytes = certificateSize;

    if (bytes == null || bytes <= 0) return 'No file attached';

    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) {
      final kb = bytes / 1024;
      return '${kb.toStringAsFixed(kb < 10 ? 1 : 0)} KB';
    }

    final mb = bytes / (1024 * 1024);
    return '${mb.toStringAsFixed(1)} MB';
  }

  /// Whether the bytes are something this app will draw rather than hand to
  /// the OS's viewer.
  bool get isImage =>
      (certificateMimeType ?? '').startsWith('image/') ||
      (certificateOriginalName ?? '').toLowerCase().endsWith('.jpg') ||
      (certificateOriginalName ?? '').toLowerCase().endsWith('.jpeg') ||
      (certificateOriginalName ?? '').toLowerCase().endsWith('.png');

  factory EmployeeTraining.fromJson(Map<String, dynamic> json) =>
      EmployeeTraining(
        id: _int(json['id']) ?? 0,
        employeeId: _int(json['employee_id']) ?? 0,
        employeeName: _nestedName(json['employee']),
        trainingProgramId: _int(json['training_program_id']) ?? 0,
        trainingProgram: json['training_program'] is Map<String, dynamic>
            ? TrainingProgram.fromJson(
                json['training_program'] as Map<String, dynamic>,
              )
            : null,
        enrollmentDate: json['enrollment_date'] as String?,
        trainingDate: json['training_date'] as String?,
        completionDate: json['completion_date'] as String?,
        trainer: json['trainer'] as String?,
        programProvider: json['program_provider'] as String?,
        status: json['status'] as String? ?? statusEnrolled,
        result: json['result'] as String?,
        remarks: json['remarks'] as String?,
        certificateNumber: json['certificate_number'] as String?,
        certificateIssueDate: json['certificate_issue_date'] as String?,
        certificateExpiryDate: json['certificate_expiry_date'] as String?,
        hasCertificate: json['has_certificate'] == true,
        certificateOriginalName: json['certificate_original_name'] as String?,
        certificateMimeType: json['certificate_mime_type'] as String?,
        certificateSize: _int(json['certificate_size']),
        certificateFileUrl: json['certificate_file_url'] as String?,
        expiryState: json['certificate_expiry_state'] as String? ?? expiryNone,
        warningDays: _int(json['warning_days']) ?? 0,
        daysUntilExpiry: _int(json['days_until_expiry']),
        expiryNotifiedAt: json['expiry_notified_at'] as String?,
        createdBy: _int(json['created_by']),
        isEditable: json['is_editable'] != false,
        isTerminal: json['is_terminal'] == true,
        isCompletable: json['is_completable'] == true,
        createdAt: json['created_at'] as String?,
      );
}

/// A nested employee brief, spelled the way `EmployeeBriefResource` and
/// `expense.dart` both spell it — an enrolment's reader and a claim's reader
/// should not disagree about which of `name` / `full_name` to draw.
String? _nestedName(Object? raw) {
  if (raw is! Map<String, dynamic>) return null;

  final name = raw['name'] ?? raw['full_name'];
  if (name is String && name.isNotEmpty) return name;

  return null;
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
