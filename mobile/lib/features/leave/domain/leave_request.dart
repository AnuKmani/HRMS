import '../../../core/data/approval_step.dart';

/// One leave request.
///
/// Matches `LeaveRequestResource`. Three things this model is careful about:
///
///  - **the day count is the server's answer.** [requestedDays] is derived
///    from the range, the weekends and the holiday calendar by
///    `LeaveDayCalculator` on the server. Nothing here recomputes it: a
///    screen that counted days itself would disagree with the reservation
///    the API made, and would be the one that was wrong.
///
///  - **the certificate block carries facts, not decisions.** Whether one is
///    required is a property of the *type*; whether the deadline has passed
///    is a property of the *record*. Both arrive ready-made, so no screen
///    re-derives either from dates it would have to copy from the docs.
///
///  - **the storage path is never present**, because the server never sends
///    it. A certificate is reached only through its own endpoint, which asks
///    for the request first — see `SickCertificateStore`.
class LeaveRequest {
  const LeaveRequest({
    required this.id,
    required this.employeeId,
    this.employeeName,
    required this.leaveTypeId,
    this.leaveTypeName,
    this.leaveTypeCode,
    this.siteId,
    this.siteName,
    required this.startDate,
    required this.endDate,
    required this.summary,
    required this.requestedDays,
    this.reason,
    required this.status,
    this.submittedAt,
    this.approvedAt,
    this.rejectedAt,
    this.cancelledAt,
    this.remarks,
    this.currentApprovalStep,
    required this.certificate,
    this.lop,
    this.approvalChain,
  });

  static const statusDraft = 'draft';
  static const statusPending = 'pending';
  static const statusApproved = 'approved';
  static const statusRejected = 'rejected';
  static const statusCancelled = 'cancelled';
  static const statusLop = 'lop';

  final int id;
  final int employeeId;
  final String? employeeName;

  final int leaveTypeId;
  final String? leaveTypeName;
  final String? leaveTypeCode;

  final int? siteId;
  final String? siteName;

  final String startDate;
  final String endDate;

  /// The server's one-line description of the range ("5 working days").
  final String summary;
  final double requestedDays;
  final String? reason;

  final String status;
  final String? submittedAt;
  final String? approvedAt;
  final String? rejectedAt;
  final String? cancelledAt;
  final String? remarks;
  final int? currentApprovalStep;

  final LeaveCertificate certificate;
  final LeaveLop? lop;

  /// Null on a list, where the chain is deliberately not fetched — fifty rows
  /// each pulling six approval records is the textbook N+1, and a list has
  /// nowhere to put them anyway. The detail screen asks for it.
  final List<ApprovalStep>? approvalChain;

  bool get isDraft => status == statusDraft;

  bool get isPending => status == statusPending;

  bool get isApproved => status == statusApproved;

  bool get isLop => status == statusLop;

  /// Still on the employee's side of the wire: the only state where the form
  /// may be re-opened or the request walked back.
  bool get isEditable => isDraft;

  /// Waiting for somebody to act — and therefore the only state where an
  /// approval endpoint could succeed.
  bool get isAwaitingDecision => isPending;

  /// What a row's trailing chip says, and its colour family, in one place so
  /// no screen invents a sixth spelling of `lop`.
  String get statusLabel => switch (status) {
    statusDraft => 'Draft',
    statusPending => 'Awaiting approval',
    statusApproved => 'Approved',
    statusRejected => 'Rejected',
    statusCancelled => 'Cancelled',
    statusLop => 'Loss of pay',
    _ => status,
  };

  /// The date range as it reads on a card: `28 Sep – 02 Oct`.
  String get dateRange => '${_short(startDate)} – ${_short(endDate)}';

  /// True when a certificate is owed and has not been filed. [dueAt] is null
  /// on a type that never asks for one, so this cannot become true by
  /// accident.
  bool get certificateOutstanding =>
      certificate.required && !certificate.hasFile && certificate.dueAt != null;

  static String _short(String iso) {
    if (iso.length < 10) return iso;

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

    final parts = iso.substring(0, 10).split('-');
    final month = int.tryParse(parts[1]);

    if (month == null || month < 1 || month > 12) return iso;

    return '${parts[2]} ${months[month - 1]}';
  }

  factory LeaveRequest.fromJson(Map<String, dynamic> json) => LeaveRequest(
    id: _int(json['id']) ?? 0,
    employeeId: _int(json['employee_id']) ?? 0,
    employeeName: _name(json['employee']),
    leaveTypeId: _int(json['leave_type_id']) ?? 0,
    leaveTypeName: _name(json['leave_type']),
    leaveTypeCode: _code(json['leave_type']),
    siteId: _int(json['site_id']),
    siteName: _nestedName(json['site']),
    startDate: json['start_date'] as String? ?? '',
    endDate: json['end_date'] as String? ?? '',
    summary: json['summary'] as String? ?? '',
    requestedDays: _double(json['requested_days']),
    reason: json['reason'] as String?,
    status: json['status'] as String? ?? statusDraft,
    submittedAt: json['submitted_at'] as String?,
    approvedAt: json['approved_at'] as String?,
    rejectedAt: json['rejected_at'] as String?,
    cancelledAt: json['cancelled_at'] as String?,
    remarks: json['remarks'] as String?,
    currentApprovalStep: _int(json['current_approval_step']),
    certificate: LeaveCertificate.fromJson(
      json['certificate'] is Map<String, dynamic>
          ? json['certificate']! as Map<String, dynamic>
          : const <String, dynamic>{},
    ),
    lop: json['lop'] is Map<String, dynamic>
        ? LeaveLop.fromJson(json['lop']! as Map<String, dynamic>)
        : null,
    approvalChain: json['approval_chain'] is List
        ? (json['approval_chain']! as List)
              .whereType<Map<String, dynamic>>()
              .map(ApprovalStep.fromJson)
              .toList()
        : null,
  );
}

/// The certificate half of a request — metadata only, and never a path.
class LeaveCertificate {
  const LeaveCertificate({
    required this.required,
    required this.hasFile,
    this.originalName,
    this.mime,
    this.size,
    this.uploadedAt,
    this.dueAt,
    required this.overdue,
  });

  final bool required;
  final bool hasFile;
  final String? originalName;
  final String? mime;
  final int? size;
  final String? uploadedAt;

  /// `YYYY-MM-DD`, and null for a type that does not ask for one.
  final String? dueAt;

  /// True only when nothing has been filed and the deadline has passed. The
  /// server decides; a client counting days would be answering the same
  /// question a second time and could disagree with the record.
  final bool overdue;

  /// The upload is server-side state, so it is worth a readable sentence a
  /// screen can show without assembling one.
  String get statusLabel {
    if (hasFile) return 'Certificate filed';
    if (overdue) return 'Certificate overdue';
    if (required && dueAt != null) return 'Certificate due $dueAt';
    if (required) return 'Certificate required';

    return 'No certificate needed';
  }

  factory LeaveCertificate.fromJson(Map<String, dynamic> json) =>
      LeaveCertificate(
        required: json['required'] == true,
        hasFile: json['has_file'] == true,
        originalName: json['original_name'] as String?,
        mime: json['mime'] as String?,
        size: _int(json['size']),
        uploadedAt: json['uploaded_at'] as String?,
        dueAt: json['due_at'] as String?,
        overdue: json['overdue'] == true,
      );
}

/// The loss-of-pay outcome, present only after a missed deadline converted
/// the request — null for every request that was not.
class LeaveLop {
  const LeaveLop({required this.days, this.reason, this.appliedAt});

  final double days;
  final String? reason;
  final String? appliedAt;

  factory LeaveLop.fromJson(Map<String, dynamic> json) => LeaveLop(
    days: _double(json['days']),
    reason: json['reason'] as String?,
    appliedAt: json['applied_at'] as String?,
  );
}

String? _name(Object? raw) {
  if (raw is Map<String, dynamic>) {
    final name = raw['name'];
    if (name is String && name.isNotEmpty) return name;
  }
  return null;
}

String? _code(Object? raw) {
  if (raw is Map<String, dynamic>) {
    final code = raw['code'];
    if (code is String && code.isNotEmpty) return code;
  }
  return null;
}

String? _nestedName(Object? raw) => _name(raw);

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
