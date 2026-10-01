import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// A file somebody chose to attach to an employment document.
///
/// Bytes and a name, and nothing else: no absolute path ever leaves this
/// class. A path is device-local detail that would have to be scrubbed from
/// every log, every error message and every payload it touched — dropping
/// it here means there is nothing to scrub.
class PickedDocument {
  const PickedDocument({required this.bytes, required this.filename});

  final Uint8List bytes;

  /// The person's own name for the file, kept because they recognise it
  /// later — and because the server reads its **extension** to decide which
  /// validation rules apply. Never used to open, store or list anything.
  final String filename;

  bool get isPdf => filename.toLowerCase().endsWith('.pdf');
}

/// Where the gallery and PDF halves of an upload come from.
///
/// An interface because `file_picker` is a platform channel: a widget test
/// awaiting it would hang on a dialog nobody answers, and every upload test
/// would need a device. The camera is *not* part of this — it already has
/// one abstraction (`SelfieCamera`) and one sheet, and a second would be a
/// duplicate picker, which scope item T explicitly rules out.
abstract class DocumentFilePicker {
  /// An image from the device's gallery, or null if the person backed out.
  Future<PickedDocument?> pickImage();

  /// A PDF from the device's storage, or null if cancelled.
  Future<PickedDocument?> pickPdf();
}

final documentFilePickerProvider = Provider<DocumentFilePicker>(
  (ref) => const DeviceDocumentFilePicker(),
);

class DeviceDocumentFilePicker implements DocumentFilePicker {
  const DeviceDocumentFilePicker();

  @override
  Future<PickedDocument?> pickImage() =>
      _pick(type: FileType.image, extensions: null, fallbackExtension: '.jpg');

  @override
  Future<PickedDocument?> pickPdf() => _pick(
    type: FileType.custom,
    extensions: const <String>['pdf'],
    fallbackExtension: '.pdf',
  );

  Future<PickedDocument?> _pick({
    required FileType type,
    required List<String>? extensions,
    required String fallbackExtension,
  }) async {
    final files = await FilePicker.pickFiles(
      type: type,
      // Only meaningful for `FileType.custom`; passing them alongside a
      // built-in filter is rejected by the plugin rather than ignored.
      allowedExtensions: extensions,
      dialogTitle: 'Attach a document',
    );

    if (files.isEmpty) return null;

    final chosen = files.first;

    // Read here rather than handing a path around: see [PickedDocument] for
    // why nothing device-local escapes this class.
    final bytes = await chosen.readAsBytes();

    var name = chosen.name.trim();

    if (name.isEmpty) name = 'document$fallbackExtension';

    // A gallery entry with no extension at all fails the server's `mimes`
    // rule before the byte-level content check is ever reached, so the
    // extension the rules read is repaired here rather than argued with
    // downstream.
    if (!_hasExtension(name)) name = '$name$fallbackExtension';

    return PickedDocument(bytes: bytes, filename: name);
  }

  bool _hasExtension(String name) => name.contains('.');
}
