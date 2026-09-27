import '../../../core/data/approval_step.dart';

/// One overtime claim.
///
/// Matches `OvertimeRequestResource`. Two pairs in here are never allowed to
/// stand in for each other:
///
///  - [requestedMinutes] and [approvedMinutes] — what was asked and what was
///    granted. Showing only one would either overstate a refusal or
///    understate a trim, and the pair is the whole story of the claim.
///
///  - [status] and [payrollEligible] — a workflow outcome and a *decision
///    payroll would read*. Only an approved request is ever eligible, but
///    the server decides the flag rather than the client deriving it from
///    the status: a client that reached a different conclusion would be
///    wrong in a way nobody could debug.
class OvertimeRequest {
  const OvertimeRequest({
    required this.id,
    required this.employeeId,
    this.employeeName,
    required this.overtimeDate,
    this.projectId,
    this.projectName,
    this.siteId,
    this.siteName,
    this.attendanceId,
    required this.requestedMinutes,
    required this.requestedHours,
    this.approvedMinutes,
    this.approvedHours,
    required this.reason,
    this.remarks,
    required this.status,
    required this.payrollEligible,
    this.currentApprovalStep,
    this.approvalChain,
    this.submittedAt,
    this.approvedAt,
    this.rejectedAt,
    this.cancelledAt,
  });

  static const statusDraft = 'draft';
  static const statusPending = 'pending';
  static const statusApproved = 'approved';
  static const statusRejected = 'rejected';
  static const statusCancelled = 'cancelled';

  static const statuses = [
    statusDraft,
    statusPending,
    statusApproved,
    statusRejected,
    statusCancelled,
  ];

  final int id;
  final int employeeId;
  final String? employeeName;
  final String overtimeDate;
  final int? projectId;
  final String? projectName;
  final int? siteId;
  final String? siteName;
  final int? attendanceId;

  final int requestedMinutes;
  final double requestedHours;
  final int? approvedMinutes;
  final double? approvedHours;

  final String reason;
  final String? remarks;
  final String status;
  final bool payrollEligible;
  final int? currentApprovalStep;

  final List<ApprovalStep>? approvalChain;
  final String? submittedAt;
  final String? approvedAt;
  final String? rejectedAt;
  final String? cancelledAt;

  bool get isDraft => status == statusDraft;

  bool get isPending => status == statusPending;

  bool get isApproved => status == statusApproved;

  /// Still on the employee's side of the wire — the only state the form may
  /// be re-opened in.
  bool get isEditable => isDraft;

  /// The only state in which an approval endpoint could succeed.
  bool get isAwaitingDecision => isPending;

  /// The figure actually worth paying, and null until something is: an
  /// approved claim with no separate `approved_minutes` granted the whole
  /// request, while anything else grants nothing at all.
  int? get payableMinutes =>
      isApproved ? (approvedMinutes ?? requestedMinutes) : null;

  bool get hasDecision =>
      approvedAt != null || rejectedAt != null || cancelledAt != null;

  String get statusLabel => switch (status) {
    statusDraft => 'Draft',
    statusPending => 'Awaiting approval',
    statusApproved => 'Approved',
    statusRejected => 'Rejected',
    statusCancelled => 'Cancelled',
    _ => status,
  };

  /// "120 min · 2.00 h" for the figure a person actually thinks in.
  String get requestedLabel =>
      '$requestedMinutes min · '
      '${requestedHours.toStringAsFixed(2)} h';

  String? get approvedLabel => approvedMinutes == null
      ? null
      : '${approvedMinutes!} min · ${(approvedHours ?? 0).toStringAsFixed(2)} h';

  factory OvertimeRequest.fromJson(Map<String, dynamic> json) =>
      OvertimeRequest(
        id: _int(json['id']) ?? 0,
        employeeId: _int(json['employee_id']) ?? 0,
        employeeName: _nestedName(json['employee']),
        overtimeDate: json['overtime_date'] as String? ?? '',
        projectId: _int(json['project_id']),
        projectName: _nestedName(json['project']),
        siteId: _int(json['site_id']),
        siteName: _nestedName(json['site']),
        attendanceId: _int(json['attendance_id']),
        requestedMinutes: _int(json['requested_minutes']) ?? 0,
        requestedHours: _double(json['requested_hours']),
        approvedMinutes: _int(json['approved_minutes']),
        approvedHours: _doubleOrNull(json['approved_hours']),
        reason: json['reason'] as String? ?? '',
        remarks: json['remarks'] as String?,
        status: json['status'] as String? ?? statusDraft,
        payrollEligible:
            json['payroll_eligible'] == true ||
            json['is_payroll_eligible'] == true,
        currentApprovalStep: _int(json['current_approval_step']),
        approvalChain: json['approval_chain'] is List
            ? (json['approval_chain']! as List)
                  .whereType<Map<String, dynamic>>()
                  .map(ApprovalStep.fromJson)
                  .toList()
            : null,
        submittedAt: json['submitted_at'] as String?,
        approvedAt: json['approved_at'] as String?,
        rejectedAt: json['rejected_at'] as String?,
        cancelledAt: json['cancelled_at'] as String?,
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

double? _doubleOrNull(Object? value) {
  if (value == null) return null;
  return _double(value);
}
