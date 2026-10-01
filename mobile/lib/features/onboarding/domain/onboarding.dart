import '../../documents/domain/employee_document.dart';

/// Where one employee stands in joining the company.
///
/// Mirrors `OnboardingResource`, which wraps the **employee** rather than the
/// onboarding row — because the list endpoint is a directory query. Every
/// person appears, with the status of a record that may not exist yet: a
/// starter nobody has opened shows `status = draft` with `exists = false`,
/// rather than being omitted, so an empty list reads as "not begun" instead
/// of "all done".
///
/// The checklist is **not** on this payload. Computing it costs a read of the
/// file and a check of the bank account, and a page of twenty rows would pay
/// for twenty of each. So [hasChecklist] is false for every row from the
/// list, and only `GET /onboarding/{employee}` — which asks for one person,
/// where the cost is expected — fills them in.
class Onboarding {
  const Onboarding({
    required this.employeeId,
    this.employeeName,
    this.employeeCode,
    required this.status,
    required this.exists,
    required this.isCompleted,
    this.startedAt,
    this.completedAt,
    this.completedBy,
    this.notes,
    this.createdAt,
    this.updatedAt,
    this.requirements = const <OnboardingChecklistItem>[],
    this.missingRequirements = const <String>[],
    this.total,
    this.satisfied,
    this.canComplete = false,
  });

  static const statusDraft = 'draft';
  static const statusPendingDocuments = 'pending_documents';
  static const statusHrReview = 'hr_review';
  static const statusCompleted = 'completed';

  static const statuses = <String>[
    statusDraft,
    statusPendingDocuments,
    statusHrReview,
    statusCompleted,
  ];

  final int employeeId;
  final String? employeeName;
  final String? employeeCode;

  final String status;

  /// Whether a row exists in `employee_onboarding` at all. An unsaved record
  /// has no `id`, no dates and no notes, and pretending otherwise would be
  /// inventing a state nobody put there.
  final bool exists;

  final bool isCompleted;

  final String? startedAt;
  final String? completedAt;
  final int? completedBy;
  final String? notes;
  final String? createdAt;
  final String? updatedAt;

  final List<OnboardingChecklistItem> requirements;
  final List<String> missingRequirements;

  /// Null on a list row, where the checklist was never computed — a zero
  /// there would read as "nothing is required", which is the exact opposite
  /// of "not yet asked".
  final int? total;
  final int? satisfied;

  /// The record's own readiness, not the reader's permission: whether *you*
  /// may press it is `onboarding.manage`, asked when you do.
  final bool canComplete;

  bool get hasChecklist => total != null;

  bool get hasProgress => total != null && satisfied != null;

  /// 0.0–1.0, or null when the checklist was not part of this payload.
  double? get progress {
    final denominator = total;
    final numerator = satisfied;

    if (denominator == null || numerator == null || denominator == 0) {
      return null;
    }

    return (numerator / denominator).clamp(0.0, 1.0);
  }

  String get statusLabel => switch (status) {
    statusDraft => 'Not started',
    statusPendingDocuments => 'Waiting on documents',
    statusHrReview => 'HR review',
    statusCompleted => 'Completed',
    _ => status,
  };

  String get statusText => statusLabel;

  /// A single line for a row: how much is outstanding, without pretending to
  /// a number the list never sent.
  String get summaryLabel => switch (status) {
    statusDraft => exists ? 'Draft' : 'Not started',
    statusPendingDocuments => 'Waiting on documents',
    statusHrReview => 'Awaiting HR review',
    statusCompleted => 'Completed',
    _ => status,
  };

  String get progressLabel {
    final current = satisfied;
    final denominator = total;

    if (current == null || denominator == null) return '';

    return '$current of $denominator requirements met';
  }

  factory Onboarding.fromJson(Map<String, dynamic> json) => Onboarding(
    employeeId: _int(json['employee_id']) ?? 0,
    employeeName: _employeeName(json['employee']),
    employeeCode: _employeeCode(json['employee']),
    status: json['status'] as String? ?? statusDraft,
    exists: json['exists'] == true,
    isCompleted: json['is_completed'] == true,
    startedAt: json['started_at'] as String?,
    completedAt: json['completed_at'] as String?,
    completedBy: _int(json['completed_by']),
    notes: json['notes'] as String?,
    createdAt: json['created_at'] as String?,
    updatedAt: json['updated_at'] as String?,
    requirements: json['requirements'] is List
        ? (json['requirements'] as List<dynamic>)
              .whereType<Map<String, dynamic>>()
              .map(OnboardingChecklistItem.fromJson)
              .toList(growable: false)
        : const <OnboardingChecklistItem>[],
    missingRequirements: json['missing_requirements'] is List
        ? (json['missing_requirements'] as List<dynamic>)
              .whereType<String>()
              .toList(growable: false)
        : const <String>[],
    total: json['totals'] is Map<String, dynamic>
        ? _int((json['totals'] as Map<String, dynamic>)['total'])
        : null,
    satisfied: json['totals'] is Map<String, dynamic>
        ? _int((json['totals'] as Map<String, dynamic>)['satisfied'])
        : null,
    canComplete: json['can_complete'] == true,
  );
}

/// One line of the onboarding checklist: a requirement, where it stands, and
/// the evidence for that answer.
///
/// The state is **neither** the requirement row nor the document row — it is
/// the answer to a question asked of both, recomputed on every read so it
/// cannot go stale. Five values, and each one says something different about
/// what to do next:
///
///  * `missing` is the employee,
///  * `pending_verification` is HR,
///  * `rejected` is the employee, with a reason on [document],
///  * `expired` is a date,
///  * `satisfied` is nobody.
///
/// Collapsing them into one boolean would throw away exactly the distinction
/// that makes the screen actionable.
class OnboardingChecklistItem {
  const OnboardingChecklistItem({
    required this.code,
    required this.name,
    this.description,
    required this.kind,
    required this.isMandatory,
    required this.sortOrder,
    required this.state,
    this.missingFields = const <String>[],
    this.document,
  });

  static const stateMissing = 'missing';
  static const statePendingVerification = 'pending_verification';
  static const stateRejected = 'rejected';
  static const stateExpired = 'expired';
  static const stateSatisfied = 'satisfied';

  /// `document` requirements are backed by a file; `data` requirements by
  /// columns on the employee; `bank` by one record that is never returned
  /// through a generic resource.
  static const kindDocument = 'document';
  static const kindData = 'data';
  static const kindBank = 'bank';

  final String code;
  final String name;
  final String? description;
  final String kind;
  final bool isMandatory;
  final int sortOrder;

  final String state;

  /// Only populated for a `data` requirement — it names the employee columns
  /// still empty, so "personal information" becomes "phone and nationality"
  /// rather than an unexplained red chip.
  final List<String> missingFields;

  final EmployeeDocument? document;

  bool get isSatisfied => state == stateSatisfied;

  bool get isRejected => state == stateRejected;

  bool get isExpired => state == stateExpired;

  bool get isPending => state == statePendingVerification;

  bool get isMissing => state == stateMissing;

  String get stateLabel => switch (state) {
    stateSatisfied => 'Met',
    stateMissing => 'Missing',
    statePendingVerification => 'Awaiting verification',
    stateRejected => 'Rejected',
    stateExpired => 'Expired',
    _ => state,
  };

  /// The line under the chip.
  ///
  /// For a `data` requirement it names the empty columns; for anything else
  /// it falls back to the document's own expiry words — because "expired" as
  /// a chip with nothing beside it is a state a person cannot act on.
  String get stateDetail {
    if (missingFields.isNotEmpty) return missingFields.join(', ');

    final document = this.document;

    if (document != null && isExpired) return document.expiryText;

    if (isRejected) return document?.rejectionReason ?? 'Needs re-filing';

    return '';
  }

  factory OnboardingChecklistItem.fromJson(Map<String, dynamic> json) =>
      OnboardingChecklistItem(
        code: json['code'] as String? ?? '',
        name: json['name'] as String? ?? '',
        description: json['description'] as String?,
        kind: json['kind'] as String? ?? kindDocument,
        isMandatory: json['is_mandatory'] != false,
        sortOrder: _int(json['sort_order']) ?? 0,
        state: json['state'] as String? ?? stateMissing,
        missingFields: json['missing_fields'] is List
            ? (json['missing_fields'] as List<dynamic>)
                  .whereType<String>()
                  .toList(growable: false)
            : const <String>[],
        document: json['document'] is Map<String, dynamic>
            ? EmployeeDocument.fromJson(
                json['document'] as Map<String, dynamic>,
              )
            : null,
      );
}

String? _employeeName(Object? raw) {
  if (raw is! Map<String, dynamic>) return null;

  final name = raw['name'] ?? raw['full_name'];

  return name is String && name.isNotEmpty ? name : null;
}

String? _employeeCode(Object? raw) {
  if (raw is! Map<String, dynamic>) return null;

  final code = raw['employee_code'];

  return code is String && code.isNotEmpty ? code : null;
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
