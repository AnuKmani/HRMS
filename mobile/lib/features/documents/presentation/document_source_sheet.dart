import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/data/device_camera.dart';
import '../../../core/presentation/camera_capture_sheet.dart';
import '../data/document_source.dart';

/// Where an employment document comes from — the three doors, in one place.
///
/// One sheet rather than three buttons scattered across two forms, because
/// the choices are the same wherever an attachment is made and a fourth
/// picker would be exactly the duplicate scope item T rules out:
///
///  * **Camera** reuses `documentCameraProvider` and `CameraCaptureSheet` —
///    the same rear-lens flow a receipt and a medical certificate use. The
///    frame is sent as it was taken; nothing is re-encoded here, because the
///    server validates the file's own bytes and a client that re-encoded
///    first would be handing it something other than what a reviewer opens
///    later.
///  * **Gallery** and **PDF** go through `DocumentFilePicker`, which exists
///    purely so a widget test can be handed bytes without a platform
///    channel in the middle.
///
/// The sheet resolves to the chosen file, or null if the person backed out
/// at any point — a cancelled picker is not an error and never draws one.
class DocumentSourceSheet extends ConsumerWidget {
  const DocumentSourceSheet({super.key});

  static const _cameraCopy = CameraCaptureCopy(
    title: 'Document',
    description:
        'Photograph the document edge to edge. It is stored privately and '
        'read only by the people who review employment files.',
    permissionDenied: 'The camera is needed to photograph the document.',
    noCamera:
        'This device has no camera, so the document cannot be photographed.',
  );

  static Future<PickedDocument?> show(BuildContext context) =>
      showModalBottomSheet<PickedDocument>(
        context: context,
        showDragHandle: true,
        builder: (context) => const DocumentSourceSheet(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
            child: Text(
              'Attach a document',
              style: theme.textTheme.titleMedium,
            ),
          ),
          ListTile(
            key: const ValueKey('document-source-camera'),
            leading: const Icon(Icons.photo_camera_outlined),
            title: const Text('Take a photo'),
            subtitle: const Text('The rear camera, at full size'),
            onTap: () => _fromCamera(context),
          ),
          ListTile(
            key: const ValueKey('document-source-gallery'),
            leading: const Icon(Icons.photo_library_outlined),
            title: const Text('Choose from gallery'),
            subtitle: const Text('A scan or screenshot you already have'),
            onTap: () => _pick(
              context,
              ref.read(documentFilePickerProvider).pickImage(),
            ),
          ),
          ListTile(
            key: const ValueKey('document-source-pdf'),
            leading: const Icon(Icons.picture_as_pdf_outlined),
            title: const Text('Choose a PDF'),
            subtitle: const Text('Contracts and certificates'),
            onTap: () =>
                _pick(context, ref.read(documentFilePickerProvider).pickPdf()),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  Future<void> _fromCamera(BuildContext context) async {
    final bytes = await CameraCaptureSheet.show(
      context,
      cameraProvider: documentCameraProvider,
      copy: _cameraCopy,
    );

    if (!context.mounted) return;

    Navigator.of(context).pop(
      bytes == null
          ? null
          : PickedDocument(bytes: bytes, filename: _frameName()),
    );
  }

  Future<void> _pick(BuildContext context, Future<PickedDocument?> pick) async {
    final chosen = await pick;

    if (!context.mounted) return;

    Navigator.of(context).pop(chosen);
  }

  /// A camera frame has no filename — the hardware hands back pixels. The
  /// extension matters only because the server's `mimes` rule reads it
  /// before the byte-level check, so a truthful one is minted here rather
  /// than left blank.
  static String _frameName() {
    final stamp = DateTime.now().millisecondsSinceEpoch;

    return 'document-$stamp.jpg';
  }
}
