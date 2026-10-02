import '../../../core/presentation/status_chip.dart';

/// One hand-over of one asset to one person — and, if there was one, the
/// hand-back.
///
/// Mirrors `AssetAssignmentResource`. **The whole row ships, including the
/// closed ones.** This is the payload behind "who had this laptop in March,
/// and what condition did it leave in?", and a client that only ever drew
/// the open hand-over would be showing a history that could not be read —
/// which is why [returnedDate] and [returnedCondition] are first-class
/// fields rather than something a second request has to be made for, and
/// why they stay `null` while the asset is still out: an asset that has not
/// come back has no return condition, and filling it with a guess would be
/// worse than an honest null.
///
/// Two more pairs that are never allowed to stand in for each other:
///
///  - [assignedCondition] and [returnedCondition] — what it was like on the
///    way out, and what it was like on the way back. The distance between
///    them is the whole argument for tracking condition at all.
///
///  - [isOverdue] and [daysOut] — is this late, and how long has it been
///    out. `isOverdue` is `false`, not `null`, when no return date was ever
///    expected: an open-ended loan is not late, it simply has no deadline,
///    and a `null` there would be read as "maybe". [daysOut] is `null`
///    while the asset is out, because the count has no end yet.
class AssetAssignment {
  const AssetAssignment({
    required this.id,
    required this.assetId,
    this.asset,
    required this.employeeId,
    this.employeeName,
    this.employee,
    this.assignedDate,
    this.expectedReturnDate,
    this.returnedDate,
    required this.assignedCondition,
    this.returnedCondition,
    this.assignedBy,
    this.returnedBy,
    required this.status,
    this.remarks,
    this.daysOut,
    required this.isOverdue,
    required this.isActive,
    this.createdAt,
    this.updatedAt,
  });

  static const statusActive = 'active';
  static const statusReturned = 'returned';

  final int id;
  final int assetId;
  final Map<String, dynamic>? asset;
  final int employeeId;
  final String? employeeName;
  final Map<String, dynamic>? employee;

  final String? assignedDate;
  final String? expectedReturnDate;
  final String? returnedDate;

  final String assignedCondition;
  final String? returnedCondition;

  final int? assignedBy;
  final int? returnedBy;

  /// One question, one column: is this hand-over still open. What condition
  /// it came back in is [returnedCondition], deliberately *not* folded in as
  /// a third or fourth status — a status asked to answer two questions
  /// eventually answers neither.
  final String status;
  final String? remarks;

  final int? daysOut;
  final bool isOverdue;
  final bool isActive;

  final String? createdAt;
  final String? updatedAt;

  bool get isOpen => status == statusActive;

  /// The thing that was handed over, read off the embedded `asset` payload.
  ///
  /// Name first, then the code, then the id — because a list that nested
  /// the asset but the operator deleted the name would still have to draw
  /// *something* identifiable in the first column, and `Asset 7` is not it.
  String get assetName {
    final raw = asset;
    if (raw == null) return '';

    final name = raw['name'];
    if (name is String && name.isNotEmpty) return name;

    final code = raw['asset_code'];
    if (code is String && code.isNotEmpty) return code;

    return 'Asset $assetId';
  }

  String get statusText => isOpen ? 'Out with holder' : 'Returned';

  StatusTone get statusTone => isOpen ? StatusTone.info : StatusTone.neutral;

  /// "2 days out" / "5 days out" / null while the count has no end yet.
  String? get daysOutLabel {
    final days = daysOut;
    if (days == null) return null;

    return days == 1 ? '1 day out' : '$days days out';
  }

  String get assignedConditionText => _conditionText(assignedCondition);

  String? get returnedConditionText =>
      returnedCondition == null ? null : _conditionText(returnedCondition!);

  /// The two dates as one line — "15 Jan 2026 → 20 Jan 2026" — so a reader
  /// can scan a column of hand-overs without pairing two cells by eye.
  String get spanLabel {
    final out = assignedDate;
    final back = returnedDate;

    if (out == null) return '';

    return back == null ? '$out →' : '$out → $back';
  }

  factory AssetAssignment.fromJson(Map<String, dynamic> json) {
    final rawAsset = json['asset'];
    final rawHolder = json['employee'] ?? json['holder'];

    return AssetAssignment(
      id: _int(json['id']) ?? 0,
      assetId: _int(json['asset_id']) ?? 0,
      asset: rawAsset is Map<String, dynamic> ? rawAsset : null,
      employeeId: _int(json['employee_id']) ?? 0,
      employeeName: _briefName(rawHolder),
      employee: rawHolder is Map<String, dynamic> ? rawHolder : null,
      assignedDate: json['assigned_date'] as String?,
      expectedReturnDate: json['expected_return_date'] as String?,
      returnedDate: json['returned_date'] as String?,
      assignedCondition: json['assigned_condition'] as String? ?? 'unknown',
      returnedCondition: json['returned_condition'] as String?,
      assignedBy: _int(json['assigned_by']),
      returnedBy: _int(json['returned_by']),
      status: json['status'] as String? ?? statusActive,
      remarks: json['remarks'] as String?,
      daysOut: _int(json['days_out']),
      isOverdue: json['is_overdue'] == true,
      isActive: json['is_active'] == true,
      createdAt: json['created_at'] as String?,
      updatedAt: json['updated_at'] as String?,
    );
  }
}

/// `EmployeeBriefResource` spells it `full_name`, and every list payload in
/// this app has said `name` at least once — so both, in that order, and
/// never a third spelling.
String? _briefName(Object? raw) {
  if (raw is! Map<String, dynamic>) return null;

  final name = raw['name'] ?? raw['full_name'];
  if (name is String && name.isNotEmpty) return name;

  final code = raw['employee_code'];
  if (code is String && code.isNotEmpty) return code;

  return null;
}

String _conditionText(String value) => switch (value) {
  'new' => 'New',
  'good' => 'Good',
  'fair' => 'Fair',
  'poor' => 'Poor',
  _ => value,
};

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
