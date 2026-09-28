/// One request for a salary certificate, and the decision on it.
///
/// The shape mirrors `SalaryCertificateRequestResource` exactly, and the one
/// field doing real work is [canIssue]: the server answers "would pressing
/// this produce a document?" by combining the caller's permission, the
/// row-level visibility and the row's own state. A screen that recomputed it
/// from [status] alone would show a download button to somebody the endpoint
/// would then refuse — the state rule and the permission rule live together
/// on purpose, in one place.
class SalaryCertificateRequest {
  const SalaryCertificateRequest({
    required this.id,
    required this.employeeId,
    this.employeeName,
    this.employeeCode,
    this.reference,
    this.requestDate,
    required this.purpose,
    required this.status,
    required this.canIssue,
    this.remarks,
    this.approvedAt,
    this.rejectedAt,
    this.cancelledAt,
    this.generatedAt,
    this.createdAt,
  });

  static const statusPending = 'pending';
  static const statusApproved = 'approved';
  static const statusRejected = 'rejected';
  static const statusGenerated = 'generated';
  static const statusCancelled = 'cancelled';

  static const statuses = [
    statusPending,
    statusApproved,
    statusRejected,
    statusGenerated,
    statusCancelled,
  ];

  final int id;
  final int employeeId;
  final String? employeeName;
  final String? employeeCode;

  /// Derived by the server from the id, so the number HR quotes in
  /// correspondence exists the moment the row does — not the moment the
  /// document is first rendered.
  final String? reference;
  final String? requestDate;
  final String purpose;

  final String status;
  final bool canIssue;

  final String? remarks;
  final String? approvedAt;
  final String? rejectedAt;
  final String? cancelledAt;
  final String? generatedAt;
  final String? createdAt;

  bool get isPending => status == statusPending;

  /// Whether the decision buttons exist at all.
  ///
  /// Deliberately **not** "may this session decide": that needs
  /// `salary_certificates.manage` *and* "this is not my own request", and
  /// the screen checks both beside it. Splitting it this way means the two
  /// halves never get answered by different sources of truth.
  bool get canBeDecided => isPending;

  /// Withdrawable while nothing has been decided — the requester's own
  /// escape hatch, or an HR desk at any point before the document exists.
  bool get canBeCancelled => isPending;

  bool get isDecided =>
      status == statusApproved ||
      status == statusRejected ||
      status == statusGenerated;

  String get statusLabel => switch (status) {
    statusPending => 'Awaiting approval',
    statusApproved => 'Approved',
    statusRejected => 'Rejected',
    statusGenerated => 'Issued',
    statusCancelled => 'Withdrawn',
    _ => status,
  };

  factory SalaryCertificateRequest.fromJson(Map<String, dynamic> json) =>
      SalaryCertificateRequest(
        id: _int(json['id']) ?? 0,
        employeeId: _int(json['employee_id']) ?? 0,
        employeeName: _nestedName(json['employee']),
        employeeCode: _nestedCode(json['employee']),
        reference: json['reference'] as String?,
        requestDate: json['request_date'] as String?,
        purpose: json['purpose'] as String? ?? '',
        status: json['status'] as String? ?? statusPending,
        canIssue: json['can_issue'] is bool
            ? json['can_issue']! as bool
            : false,
        remarks: json['remarks'] as String?,
        approvedAt: json['approved_at'] as String?,
        rejectedAt: json['rejected_at'] as String?,
        cancelledAt: json['cancelled_at'] as String?,
        generatedAt: json['generated_at'] as String?,
        createdAt: json['created_at'] as String?,
      );
}

String? _nestedName(Object? raw) =>
    raw is Map<String, dynamic> && raw['full_name'] is String
    ? raw['full_name'] as String
    : null;

String? _nestedCode(Object? raw) =>
    raw is Map<String, dynamic> && raw['employee_code'] is String
    ? raw['employee_code'] as String
    : null;

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
