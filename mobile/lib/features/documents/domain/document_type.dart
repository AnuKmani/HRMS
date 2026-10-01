import 'package:flutter/material.dart';

import '../../../core/presentation/status_chip.dart';

/// One kind of document an employment file can hold — and the three rules it
/// asks of anything filed under it.
///
/// Mirrors `DocumentTypeResource`. The point of shipping `requires_*` to the
/// client is the same reason ExpenseResource ships `requires_receipt`: a form
/// that does not know "this type needs a number" would let somebody finish
/// one that was never going to be accepted, and a 422 after the fact is a
/// worse experience than a field marked required before it is typed into.
///
/// There are **no hard-coded rules about passport, Emirates ID or visa
/// anywhere in this app** — a deployment may rename a code, retire a type or
/// add its own, and every screen here asks the server what is required
/// rather than matching on a name.
class DocumentType {
  const DocumentType({
    required this.id,
    required this.name,
    required this.code,
    this.description,
    required this.requiresDocumentNumber,
    required this.requiresIssueDate,
    required this.requiresExpiryDate,
    required this.expiryWarningDays,
    required this.status,
    required this.sortOrder,
  });

  static const statusActive = 'active';

  final int id;
  final String name;
  final String code;
  final String? description;

  final bool requiresDocumentNumber;
  final bool requiresIssueDate;
  final bool requiresExpiryDate;

  /// Days before expiry this kind starts reading as "expiring soon".
  ///
  /// Zero does **not** mean "never warns" — it means the configured default
  /// applies, which is exactly how the server's `warningDays()` reads it and
  /// how this client must read it too: a chip that printed "in 0 days"
  /// because it took the zero literally would be a lie on the one screen
  /// whose job is telling the truth about dates.
  final int expiryWarningDays;

  final String status;
  final int sortOrder;

  bool get isActive => status == statusActive;

  /// Whether a form for this type must collect an expiry date.
  bool get needsExpiry => requiresExpiryDate;

  String get fallbackWarningLabel =>
      expiryWarningDays > 0 ? '$expiryWarningDays days' : 'Default window';

  factory DocumentType.fromJson(Map<String, dynamic> json) => DocumentType(
    id: _int(json['id']) ?? 0,
    name: json['name'] as String? ?? '',
    code: json['code'] as String? ?? '',
    description: json['description'] as String?,
    requiresDocumentNumber: json['requires_document_number'] == true,
    requiresIssueDate: json['requires_issue_date'] == true,
    requiresExpiryDate: json['requires_expiry_date'] == true,
    expiryWarningDays: _int(json['expiry_warning_days']) ?? 0,
    status: json['status'] as String? ?? statusActive,
    sortOrder: _int(json['sort_order']) ?? 0,
  );
}

/// How a document status reads on a row.
///
/// Five values, and the same palette the leave, overtime and expense lists
/// use, so this app never shows two different reds that both mean "refused".
StatusTone toneForDocumentStatus(String status) => switch (status) {
  'valid' => StatusTone.positive,
  'rejected' => StatusTone.negative,
  'expired' => StatusTone.negative,
  'pending' => StatusTone.info,
  _ => StatusTone.neutral,
};

/// The expiry window this kind warns about, as words.
///
/// Never a colour and never a bare number: `warningLabel` is what a chip
/// prints, and it says something whether or not a colour is next to it.
String warningLabelFor(int days) => days > 0 ? '$days days' : 'Default window';

/// Whether this type has anything date-shaped to show at all.
bool typeHasDates(DocumentType type) =>
    type.requiresIssueDate || type.requiresExpiryDate;

/// A compact "what does this type demand?" line for a form or a picker.
String requirementsLabel(DocumentType type) {
  final parts = <String>[
    if (type.requiresDocumentNumber) 'number',
    if (type.requiresIssueDate) 'issue date',
    if (type.requiresExpiryDate) 'expiry date',
  ];

  return parts.isEmpty
      ? 'No extra details required'
      : 'Needs ${parts.join(', ')}';
}

IconData iconForDocumentType(String code) => switch (code) {
  'PASSPORT' => Icons.book_outlined,
  'EMIRATES_ID' => Icons.badge_outlined,
  'VISA' => Icons.flight_takeoff_outlined,
  'EMPLOYMENT_CONTRACT' => Icons.description_outlined,
  'CERTIFICATE' => Icons.workspace_premium_outlined,
  'TRAINING_CERTIFICATE' => Icons.school_outlined,
  'MEDICAL_DOCUMENT' => Icons.medical_services_outlined,
  'LABOUR_DOCUMENTS' => Icons.work_outline,
  _ => Icons.folder_outlined,
};

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
