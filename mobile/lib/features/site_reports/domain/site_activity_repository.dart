import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import '../../sites/domain/site.dart';
import 'site_activity_report.dart';
import 'site_report_photo.dart';

/// How the field-reporting feature reaches the server.
///
/// Three shapes live behind this contract, and each one is here for a
/// reason the others cannot cover:
///
///  - **the report itself** is ordinary CRUD with a fifth verb, `submit`,
///    because there is no status field in any payload — `draft -> submitted`
///    is a transition with a precondition (the fix, the photographs, the
///    words), not a column a form may set.
///  - **photographs** travel one batch at a time rather than inside the
///    create body. A report saved with three 4 MB frames otherwise
///    re-uploads all three on every correction, and a site with one bar of
///    signal never finishes an edit.
///  - **the picker** answers "which sites may this session file about?",
///    which is a *different* question from `GET /sites` — a field worker
///    holds no `sites.view`, and the ordinary directory is closed to
///    exactly the role this screen is built for.
///
/// Bytes rather than files: the repository takes raw frames and the API
/// layer builds the multipart body, so a test can assert on "were these six
/// sent?" without a platform file system to put them on first.
abstract class SiteActivityRepository {
  Future<PageResult<SiteActivityReport>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<SiteActivityReport> find(int id);

  /// Files a new report. The server reads the author from the session and
  /// the project from the site; neither is in [body] and neither can be.
  Future<SiteActivityReport> create(Map<String, Object?> body);

  Future<SiteActivityReport> update(int id, Map<String, Object?> body);

  /// The one transition this module has.
  ///
  /// The fix is required here and optional on the draft: a note written in
  /// a basement is a normal thing to have, a submitted report that cannot
  /// say where it was written is not.
  Future<SiteActivityReport> submit(
    int id, {
    required double latitude,
    required double longitude,
    required double accuracy,
  });

  Future<List<SiteReportPhoto>> addPhotos(
    int reportId,
    List<Uint8List> photos, {
    String? caption,
  });

  Future<void> removePhoto(int reportId, int photoId);

  /// The sites this session may file about — see the note above.
  Future<PageResult<Site>> reportableSites({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });
}
