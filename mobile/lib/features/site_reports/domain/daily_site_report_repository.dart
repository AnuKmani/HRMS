import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import 'daily_site_report.dart';
import 'site_report_photo.dart';

/// How the official site-day document is read and written.
///
/// Shaped like [SiteActivityRepository]'s report half and unlike it in three
/// places, each of which is a rule rather than a preference:
///
///  - `submit` takes no body at all. The daily report needs no fix, and
///    every column that could move at submission time is already on the
///    row; an empty body is what stops `status` from ever being an argument.
///  - `pdf` returns **bytes**. The document is generated on demand, never
///    stored, so there is no URL to hand back and no file that could go
///    stale beside the row it was copied from.
///  - there is no `approve`. `approved_at` exists on the table and is
///    reserved for a later phase; offering an endpoint for it now would be
///    offering a status this build cannot honour.
abstract class DailySiteReportRepository {
  Future<PageResult<DailySiteReport>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<DailySiteReport> find(int id);

  Future<DailySiteReport> create(Map<String, Object?> body);

  Future<DailySiteReport> update(int id, Map<String, Object?> body);

  Future<DailySiteReport> submit(int id);

  Future<List<SiteReportPhoto>> addPhotos(
    int reportId,
    List<Uint8List> photos, {
    String? caption,
  });

  Future<void> removePhoto(int reportId, int photoId);

  /// The document, freshly rendered from the row as it stands right now.
  Future<Uint8List> pdf(int id);
}
