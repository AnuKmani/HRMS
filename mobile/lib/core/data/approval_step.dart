/// One materialised step of an approval chain.
///
/// Lives in `core` rather than in one feature because leave and overtime are
/// the same chain on the same engine — a step belongs to whichever request is
/// showing it, and duplicating the class would guarantee the two drifted
/// apart on what `isCurrent` means.
///
/// Matches `ApprovalRecordResource`. The step is frozen when its request is
/// submitted, so `approverEmployeeId` is whatever the server pinned at that
/// moment; re-resolving it now could name a different person for the same
/// history, which is exactly why the server sends the answer instead of the
/// ingredients.
class ApprovalStep {
  const ApprovalStep({
    required this.id,
    required this.sequence,
    required this.name,
    required this.approverType,
    this.approverRole,
    this.approverPermission,
    this.approverEmployeeId,
    required this.status,
    required this.isCurrent,
    this.actedBy,
    this.actedAt,
    this.remarks,
    this.resolvedApproverName,
  });

  final int id;
  final int sequence;
  final String name;

  /// `reporting_manager`, `role` or `permission`.
  final String approverType;
  final String? approverRole;
  final String? approverPermission;
  final int? approverEmployeeId;

  /// `pending`, `approved`, `rejected` — or `skipped` when the step could not
  /// be resolved to anybody at submit time. A skipped step is recorded, never
  /// deleted, and the screen shows it as what it is rather than hiding it.
  final String status;

  final bool isCurrent;
  final int? actedBy;
  final String? actedAt;
  final String? remarks;
  final String? resolvedApproverName;

  bool get isPending => status == 'pending';

  bool get isApproved => status == 'approved';

  bool get isRejected => status == 'rejected';

  bool get isSkipped => status == 'skipped';

  /// Who this step is waiting on, phrased the way a person would say it.
  ///
  /// A resolved person wins: when the chain pinned "your Project Manager" to
  /// an actual employee at submit time, naming the role as well would
  /// contradict the row it is describing.
  String get approverLabel {
    if (resolvedApproverName != null && resolvedApproverName!.isNotEmpty) {
      return resolvedApproverName!;
    }
    if (approverRole != null && approverRole!.isNotEmpty) return approverRole!;
    if (approverPermission != null && approverPermission!.isNotEmpty) {
      return 'Anyone with ${approverPermission!}';
    }

    return 'Your reporting manager';
  }

  String get statusLabel => switch (status) {
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'skipped' => 'Skipped',
    _ => 'Waiting',
  };

  factory ApprovalStep.fromJson(Map<String, dynamic> json) => ApprovalStep(
    id: _int(json['id']) ?? 0,
    sequence: _int(json['sequence']) ?? 0,
    name: json['name'] as String? ?? '',
    approverType: json['approver_type'] as String? ?? 'reporting_manager',
    approverRole: json['approver_role'] as String?,
    approverPermission: json['approver_permission'] as String?,
    approverEmployeeId: _int(json['approver_employee_id']),
    status: json['status'] as String? ?? 'pending',
    isCurrent: json['is_current'] == true,
    actedBy: _int(json['acted_by']),
    actedAt: json['acted_at'] as String?,
    remarks: json['remarks'] as String?,
    resolvedApproverName: _personName(json['resolved_approver']),
  );
}

String? _personName(Object? raw) {
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
