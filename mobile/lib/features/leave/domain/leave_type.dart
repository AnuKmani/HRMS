/// A configurable leave type — one row per kind of leave an organisation
/// offers, and the only place a leave rule is allowed to live.
///
/// Matches `LeaveTypeResource`. Nothing in this app hard-codes "annual leave
/// is 12 days" or "sick leave needs a certificate": those are columns on this
/// row, read by the services on the server and mirrored here only so a form
/// can say what it is about to ask for. A row that disappears or is disabled
/// changes the next request on the next fetch rather than requiring a release.
class LeaveType {
  const LeaveType({
    required this.id,
    required this.name,
    required this.code,
    this.description,
    required this.entitlementDays,
    required this.carryForwardEnabled,
    required this.carryForwardLimit,
    required this.maximumDaysPerRequest,
    required this.isPaid,
    required this.requiresDocument,
    required this.documentDeadlineDays,
    required this.allowNegativeBalance,
    required this.status,
    this.approvalWorkflowId,
  });

  final int id;
  final String name;
  final String code;
  final String? description;

  final int entitlementDays;
  final bool carryForwardEnabled;
  final int carryForwardLimit;
  final int? maximumDaysPerRequest;

  final bool isPaid;
  final bool requiresDocument;
  final int documentDeadlineDays;
  final bool allowNegativeBalance;

  final String status;
  final int? approvalWorkflowId;

  bool get isActive => status == 'active';

  /// Unpaid leave is how a request gets past the balance check on the server,
  /// and it is the one fact a form cannot show "as a number" — so it is worth
  /// a getter of its own rather than every caller repeating `!isPaid`.
  bool get isUnpaid => !isPaid;

  /// What the picker shows: the name, and the terms in brackets so somebody
  /// choosing between three options can see the difference without opening
  /// each one.
  String get pickerLabel {
    final parts = <String>[
      name,
      if (isPaid) '$entitlementDays days' else 'unpaid',
      if (requiresDocument) 'certificate',
    ];

    return parts.join(' · ');
  }

  /// The sentence a form shows under the date pickers when this type is the
  /// one selected.
  String get helpText {
    final parts = <String>[
      if (isPaid) 'Paid leave' else 'Unpaid leave',
      if (entitlementDays > 0) 'Entitlement $entitlementDays days',
      if (maximumDaysPerRequest != null)
        'Up to $maximumDaysPerRequest days per request',
      if (requiresDocument)
        'A medical certificate is due within '
            '$documentDeadlineDays days of the last day',
    ];

    return parts.join(' · ');
  }

  factory LeaveType.fromJson(Map<String, dynamic> json) => LeaveType(
    id: _int(json['id']) ?? 0,
    name: json['name'] as String? ?? '',
    code: json['code'] as String? ?? '',
    description: json['description'] as String?,
    entitlementDays: _int(json['entitlement_days']) ?? 0,
    carryForwardEnabled: json['carry_forward_enabled'] == true,
    carryForwardLimit: _int(json['carry_forward_limit']) ?? 0,
    maximumDaysPerRequest: _int(json['maximum_days_per_request']),
    isPaid: json['is_paid'] == true,
    requiresDocument: json['requires_document'] == true,
    documentDeadlineDays: _int(json['document_deadline_days']) ?? 0,
    allowNegativeBalance: json['allow_negative_balance'] == true,
    status: json['status'] as String? ?? 'active',
    approvalWorkflowId: _int(json['approval_workflow_id']),
  );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
