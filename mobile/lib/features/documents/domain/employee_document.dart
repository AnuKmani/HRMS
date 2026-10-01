import 'package:flutter/material.dart';

import '../../../core/presentation/status_chip.dart';
import 'document_type.dart';

/// One document in one person's employment file.
///
/// Mirrors `EmployeeDocumentResource`. Three pairs in here are never allowed
/// to stand in for each other:
///
///  - [status] and [expiryState] — the stored decision (somebody verified
///    this, or the scan marked it lapsed) and the date arithmetic computed
///    *right now* on the server. The client renders both and derives
///    neither: a `valid` document whose date passed yesterday reports
///    `expired` whether or not the scheduler has caught up, so a lagging
///    cron cannot make this app tell a lie.
///
///  - [hasFile] and [fileUrl] — whether there is one, and the one route that
///    will serve it. **There is no `path` anywhere in this payload and none
///    in this class**, because a stored path is exactly the detail that
///    should not leave the server.
///
///  - [daysUntilExpiry] and [expiryDate] — a signed count and the date it
///    was counted from. The sign carries the direction, so no screen has to
///    pair the number with its own comparison to know which way it points.
class EmployeeDocument {
  const EmployeeDocument({
    required this.id,
    required this.employeeId,
    this.employeeName,
    required this.documentTypeId,
    this.documentType,
    this.documentNumber,
    this.issueDate,
    this.expiryDate,
    this.notes,
    required this.hasFile,
    this.originalName,
    this.mimeType,
    this.fileSize,
    this.fileUrl,
    required this.status,
    required this.expiryState,
    required this.warningDays,
    this.daysUntilExpiry,
    this.expiryNotifiedAt,
    this.rejectionReason,
    this.uploadedBy,
    this.verifiedAt,
    this.verifiedBy,
    this.archivedAt,
    required this.isEditable,
    required this.isPending,
    this.createdAt,
  });

  static const statusPending = 'pending';
  static const statusValid = 'valid';
  static const statusExpired = 'expired';
  static const statusRejected = 'rejected';
  static const statusArchived = 'archived';

  static const statuses = [
    statusPending,
    statusValid,
    statusExpired,
    statusRejected,
    statusArchived,
  ];

  static const expiryNone = 'none';
  static const expiryValid = 'valid';
  static const expirySoon = 'expiring_soon';
  static const expiryExpired = 'expired';

  final int id;
  final int employeeId;
  final String? employeeName;

  final int documentTypeId;
  final DocumentType? documentType;

  final String? documentNumber;
  final String? issueDate;
  final String? expiryDate;
  final String? notes;

  final bool hasFile;
  final String? originalName;
  final String? mimeType;
  final int? fileSize;

  /// An API route, never a public link: it needs the same bearer token as
  /// everything else and is answered by EmployeeDocumentPolicy. There is no
  /// signed URL, no expiry on it, and nothing for a browser to cache.
  final String? fileUrl;

  final String status;

  /// Server-computed from the date *right now* — rendered, never derived.
  final String expiryState;
  final int warningDays;
  final int? daysUntilExpiry;
  final String? expiryNotifiedAt;

  final String? rejectionReason;
  final int? uploadedBy;
  final String? verifiedAt;
  final int? verifiedBy;
  final String? archivedAt;

  /// Record state, not reader permission: whether *you* may act is
  /// PermissionScope's answer, asked when you try.
  final bool isEditable;
  final bool isPending;

  final String? createdAt;

  String get typeName => documentType?.name ?? '';

  String get typeCode => documentType?.code ?? '';

  bool get isArchived => status == statusArchived;

  bool get isVerified => status == statusValid || verifiedAt != null;

  String get statusText => switch (status) {
    statusPending => 'Awaiting verification',
    statusValid => 'Verified',
    statusExpired => 'Expired',
    statusRejected => 'Rejected',
    statusArchived => 'Archived',
    _ => status,
  };

  StatusTone get statusTone => toneForDocumentStatus(status);

  /// The expiry chip's words.
  ///
  /// **Always words, never a colour alone** (scope item U): every one of the
  /// four states reads differently, so a colour-blind reader — or a
  /// screenshot pasted into an email — carries the same information as the
  /// tinted chip beside it. `daysUntilExpiry` is signed, so the direction is
  /// in the number rather than in a comparison the screen would have to
  /// repeat.
  String get expiryText => switch (expiryState) {
    expiryNone => 'No expiry date',
    expiryExpired => _absoluteDays(isPast: true),
    expirySoon => _absoluteDays(isPast: false),
    expiryValid => _absoluteDays(isPast: false),
    _ => expiryDate == null ? 'No expiry date' : 'Expires ${expiryDate!}',
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
  /// label on the screen a person acts on: "in 1 days" reads as a bug and
  /// makes the reader distrust the number next to it.
  String _absoluteDays({required bool isPast}) {
    final days = daysUntilExpiry;

    if (days == null) {
      return expiryDate == null
          ? 'No expiry date'
          : (isPast ? 'Expired ${expiryDate!}' : 'Expires ${expiryDate!}');
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
    final bytes = fileSize;

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
      (mimeType ?? '').startsWith('image/') ||
      (originalName ?? '').toLowerCase().endsWith('.jpg') ||
      (originalName ?? '').toLowerCase().endsWith('.jpeg') ||
      (originalName ?? '').toLowerCase().endsWith('.png');

  factory EmployeeDocument.fromJson(Map<String, dynamic> json) =>
      EmployeeDocument(
        id: _int(json['id']) ?? 0,
        employeeId: _int(json['employee_id']) ?? 0,
        employeeName: _nestedName(json['employee']),
        documentTypeId: _int(json['document_type_id']) ?? 0,
        documentType: json['document_type'] is Map<String, dynamic>
            ? DocumentType.fromJson(
                json['document_type'] as Map<String, dynamic>,
              )
            : null,
        documentNumber: json['document_number'] as String?,
        issueDate: json['issue_date'] as String?,
        expiryDate: json['expiry_date'] as String?,
        notes: json['notes'] as String?,
        // Absent means no file — not "assume one". A payload that omitted
        // the flag entirely is treated as having nothing to open, which is
        // the smaller error beside a download button that 404s.
        hasFile: json['has_file'] == true,
        originalName: json['original_name'] as String?,
        mimeType: json['mime_type'] as String?,
        fileSize: _int(json['file_size']),
        fileUrl: json['file_url'] as String?,
        status: json['status'] as String? ?? statusPending,
        expiryState: json['expiry_state'] as String? ?? expiryNone,
        warningDays: _int(json['warning_days']) ?? 0,
        daysUntilExpiry: _int(json['days_until_expiry']),
        expiryNotifiedAt: json['expiry_notified_at'] as String?,
        rejectionReason: json['rejection_reason'] as String?,
        uploadedBy: _int(json['uploaded_by']),
        verifiedAt: json['verified_at'] as String?,
        verifiedBy: _int(json['verified_by']),
        archivedAt: json['archived_at'] as String?,
        isEditable: json['is_editable'] != false,
        isPending: json['is_pending'] == true,
        createdAt: json['created_at'] as String?,
      );
}

/// A nested `name`, with `full_name` as the employee resource spells it —
/// the same helper `_nestedName` in `expense.dart` exists for, because an
/// employee brief and a document type both arrive as an object and reading
/// one spelling off two shapes is how a detail screen ends up blank for one
/// record type only.
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
