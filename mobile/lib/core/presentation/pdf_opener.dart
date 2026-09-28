import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

/// Puts a PDF the API has already rendered in front of the person who asked
/// for it.
///
/// An interface rather than a free function for the same reason the site
/// report's own opener is one: a screen's spinner, failure banner and
/// "nothing could open this" wording all hang off a single object a test can
/// replace, so none of them needs a file system, an installed viewer or a
/// server to be exercised.
///
/// **Bytes, not ids.** Everything Phase 8 produces — a salary slip, a
/// certificate — is rendered on demand and handed over immediately. There is
/// no stored file to fetch twice and no URL that outlives the request, which
/// is the whole point of generating from the row rather than from a copy.
abstract class PdfOpener {
  Future<void> openBytes(Uint8List bytes, String filename);
}

final pdfOpenerProvider = Provider<PdfOpener>((ref) => const DevicePdfOpener());

/// The real one: a private temp file and the OS's viewer.
class DevicePdfOpener implements PdfOpener {
  const DevicePdfOpener();

  @override
  Future<void> openBytes(Uint8List bytes, String filename) async {
    final directory = await getTemporaryDirectory();

    // A temp directory *inside the app's own sandbox* is the private half of
    // "no permanent public URL": nothing here is world-readable, and nothing
    // survives as a link somebody could forward.
    final file = File(
      '${directory.path}${Platform.pathSeparator}$safePdfFilename(filename)',
    );

    await file.writeAsBytes(bytes, flush: true);

    final result = await OpenFilex.open(file.path);

    if (result.type != ResultType.done) {
      throw StateError(
        'The PDF was downloaded but no app on this device could open it. '
        'Try again from a device with a PDF reader installed.',
      );
    }
  }
}

/// Strips anything a path could be made of.
///
/// Defence in depth rather than in response to an actual threat: a filename
/// here is built from a period label or an id, not from a text field, and
/// one rule is cheaper than being sure of that forever. A name that becomes
/// *nothing* under the rule falls back rather than emptying to a directory.
String safePdfFilename(String name, {String fallback = 'document.pdf'}) {
  final cleaned = name.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');

  return cleaned.isEmpty || cleaned == '.' || cleaned == '..'
      ? fallback
      : cleaned;
}
