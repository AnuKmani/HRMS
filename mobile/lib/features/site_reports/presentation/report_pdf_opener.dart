import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../data/api_daily_site_report_repository.dart';
import '../domain/daily_site_report_repository.dart';

/// Fetches a freshly rendered report PDF and puts it where a person can
/// actually open it.
///
/// An interface so the whole screen flow — the spinner, the failure
/// banner, the specific wording for a refusal — can be exercised without a
/// file system, an installed PDF viewer, or a server. The real
/// implementation is the last twenty lines of this file and does three
/// unglamorous things: ask the API for the bytes, write them into the app's
/// own temporary directory, and hand that path to the OS.
///
/// **On demand, never stored.** There is no URL to keep here because the
/// document does not exist until it is asked for: the server renders it
/// from the row as it stands, so a report corrected at 16:00 cannot leave a
/// 09:00 PDF sitting in a download folder looking authoritative. The
/// consequence for this class is that `open` is a network call wearing a
/// file-access costume, and the UI has to be honest about that with a
/// loading state rather than pretending a local file is being opened.
abstract class ReportPdfOpener {
  Future<void> open(int id, {String? filename});
}

final reportPdfOpenerProvider = Provider<ReportPdfOpener>((ref) {
  final opener = DeviceReportPdfOpener(
    ref.watch(dailySiteReportRepositoryProvider),
  );

  return opener;
});

/// The real one: bytes from the API, a private temp file, the OS's viewer.
class DeviceReportPdfOpener implements ReportPdfOpener {
  DeviceReportPdfOpener(this._repository);

  final DailySiteReportRepository _repository;

  @override
  Future<void> open(int id, {String? filename}) async {
    final bytes = await _repository.pdf(id);

    final directory = await getTemporaryDirectory();

    // The name is only ever shown in the viewer's title bar, and it is
    // built here rather than trusted from a `Content-Disposition`: a
    // filename is user-visible text, and a server that has already been
    // asked to render the document is not the party this needs to defend
    // against. What must *not* happen is the file landing somewhere shared
    // — a temp directory inside the app's own sandbox is the private half
    // of "no permanent public URL".
    final safeName = _safeName(filename ?? 'report-$id.pdf');

    final file = File('${directory.path}${Platform.pathSeparator}$safeName');
    await file.writeAsBytes(bytes, flush: true);

    final result = await OpenFilex.open(file.path);

    if (result.type != ResultType.done) {
      throw StateError(
        'The PDF was downloaded but no app on this device could open it. '
        'Try again from a device with a PDF reader installed.',
      );
    }
  }

  /// Strips anything a path could be made of. Defence in depth rather than
  /// in response to an actual threat: the string never came from a user,
  /// and one rule is cheaper than being sure of that forever.
  static String _safeName(String name) {
    final cleaned = name.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');

    return cleaned.isEmpty || cleaned == '.' || cleaned == '..'
        ? 'report.pdf'
        : cleaned;
  }
}

/// The `daily-site-report-3-28092026.pdf` half of the download UX — the
/// date comes from the report, not from the clock, so a document opened
/// tomorrow still names the day it describes.
String reportPdfFilename(int id, String reportDate) {
  final parts = reportDate.split('-');

  if (parts.length != 3) return 'daily-site-report-$id.pdf';

  // `ddMMyyyy`, matching the server's own attachment name.
  final compact = parts[2] + parts[1] + parts[0];

  return 'daily-site-report-$id-$compact.pdf';
}
