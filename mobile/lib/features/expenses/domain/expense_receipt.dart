/// One receipt attached to a claim — its metadata, and never its bytes.
///
/// Matches `ExpenseReceiptResource`. What this class deliberately does *not*
/// carry is the point of it: there is no storage path in the payload, because
/// a path is a description of where a private file lives and the server never
/// sends one. The only way to open a receipt is [id] through
/// `GET /api/v1/expenses/{expense}/receipts/{receipt}`, which is behind the
/// same policy that already governs the claim it hangs off.
///
/// `originalName` is here because a person recognises "IMG_0142.jpg" faster
/// than a uuid. It is display text only — nothing in this app opens, serves
/// or resolves anything from it, and the download's filename is minted by
/// the server instead.
class ExpenseReceipt {
  const ExpenseReceipt({
    required this.id,
    required this.expenseId,
    required this.originalName,
    required this.mimeType,
    required this.sizeBytes,
    required this.isImage,
    required this.isPdf,
    this.uploadedBy,
    this.createdAt,
  });

  final int id;
  final int expenseId;
  final String originalName;
  final String mimeType;
  final int sizeBytes;
  final bool isImage;
  final bool isPdf;
  final int? uploadedBy;
  final String? createdAt;

  /// Whether this screen can draw the document inline.
  ///
  /// Only images qualify. A PDF's bytes are a document rather than a picture,
  /// so they are handed to the platform's opener rather than to
  /// `Image.memory`, which would render a rectangle of noise.
  bool get canPreview => isImage;

  /// "1.2 MB", "412 KB", "18 B" — one decimal place above a kilobyte,
  /// because "1.24 MB" is the size a person compares and "1240304 bytes" is
  /// the size a machine compares.
  String get sizeLabel {
    if (sizeBytes < 1024) return '$sizeBytes B';
    if (sizeBytes < 1024 * 1024) {
      return '${(sizeBytes / 1024).toStringAsFixed(1)} KB';
    }
    return '${(sizeBytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }

  factory ExpenseReceipt.fromJson(Map<String, dynamic> json) => ExpenseReceipt(
    id: _int(json['id']) ?? 0,
    expenseId: _int(json['expense_id']) ?? 0,
    originalName: json['original_name'] as String? ?? '',
    mimeType: json['mime_type'] as String? ?? '',
    sizeBytes: _int(json['size_bytes']) ?? 0,
    isImage: json['is_image'] == true,
    isPdf: json['is_pdf'] == true,
    uploadedBy: _int(json['uploaded_by']),
    createdAt: json['created_at'] as String?,
  );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
