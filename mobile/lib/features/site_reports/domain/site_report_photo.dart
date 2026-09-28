/// One photograph attached to a site report, as the API describes it.
///
/// Deliberately **no path and no URL**. The server never sends one â€” the
/// bytes live on a private disk behind `GET â€¦/{report}/photos/{photo}`, which
/// asks the report's policy before it answers â€” so there is nothing here for
/// a log, a screenshot or a serialized screen state to leak. What the UI
/// needs to *fetch* a frame is the two ids it already has, and the base path
/// is a property of the report the photo belongs to rather than of the photo
/// itself: a photograph has no idea which endpoint it was uploaded through.
class SiteReportPhoto {
  const SiteReportPhoto({
    required this.id,
    required this.sortOrder,
    this.caption,
    this.mimeType,
    this.sizeBytes,
    this.createdAt,
  });

  final int id;
  final String? caption;

  /// Where this frame sits in the document's own order â€” the order the
  /// server assigned at upload, not the order the phone happened to finish
  /// sending them in.
  final int sortOrder;

  final String? mimeType;
  final int? sizeBytes;
  final String? createdAt;

  /// Decides which resource a thumbnail URL belongs to.
  ///
  /// Held by the *report* rather than written into every photo: the two
  /// endpoints differ only in their first segment, and spelling that
  /// segment in six call sites would mean six places to get wrong when a
  /// third report type arrives.
  static String pathFor(String base, int reportId, int photoId) =>
      '$base/$reportId/photos/$photoId';

  factory SiteReportPhoto.fromJson(Map<String, dynamic> json) =>
      SiteReportPhoto(
        id: _int(json['id']) ?? 0,
        caption: json['caption'] as String?,
        sortOrder: _int(json['sort_order']) ?? 0,
        mimeType: json['mime_type'] as String?,
        sizeBytes: _int(json['size_bytes']),
        createdAt: json['created_at'] as String?,
      );
}

/// Reads the photos of either report type from a decoded envelope.
///
/// Absent is a *loaded list that was not asked for* â€” the list endpoints
/// deliberately omit the children so a page of fifty reports costs fifty
/// rows rather than fifty rows plus two hundred photographs â€” and is kept as
/// an empty list rather than `null`, because a screen that draws a photo
/// grid never has a third meaning to handle.
List<SiteReportPhoto> photosFrom(Object? raw) {
  if (raw is! List) return const <SiteReportPhoto>[];

  return raw
      .whereType<Map<String, dynamic>>()
      .map(SiteReportPhoto.fromJson)
      .toList(growable: false);
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
