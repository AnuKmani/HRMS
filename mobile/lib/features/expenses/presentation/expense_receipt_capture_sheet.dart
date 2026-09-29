import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../core/data/device_camera.dart';
import '../../../core/presentation/camera_capture_sheet.dart';

/// Filing a receipt, start to finish — the shared capture flow wearing this
/// feature's words.
///
/// Three decisions the copy encodes, all worth stating:
///
///  * **the rear camera.** A receipt is a slip on a table, not a face, so the
///    flow opens `documentCameraProvider` rather than the front lens a
///    check-in uses. Sharing one implementation is what makes that a
///    parameter instead of a second copy of a hundred-line widget.
///
///  * **the frame is sent as it was taken.** Nothing is re-encoded here: the
///    server validates the file's own bytes (extension, MIME and a byte-level
///    content check) and a client that re-encoded first would be handing it
///    something other than what a reviewer will open later.
///
///  * **where it ends up is said out loud.** A person photographing a
///    company card slip wants to know who sees it, so the description names
///    the private store and the reviewers rather than leaving the destination
///    to be guessed from the fact that an upload happened.
///
/// The upload itself is the caller's business — this returns bytes, or null
/// if the person backed out.
class ExpenseReceiptCaptureSheet extends StatelessWidget {
  const ExpenseReceiptCaptureSheet({super.key});

  static const _copy = CameraCaptureCopy(
    title: 'Receipt',
    description:
        'Photograph the receipt. It is stored privately and read only by '
        'the people who review your expenses.',
    permissionDenied: 'The camera is needed to photograph your receipt.',
    noCamera:
        'This device has no camera, so the receipt cannot be photographed.',
  );

  /// Opens the sheet and resolves to the raw JPEG bytes, or null if
  /// cancelled.
  static Future<Uint8List?> show(BuildContext context) =>
      CameraCaptureSheet.show(
        context,
        cameraProvider: documentCameraProvider,
        copy: _copy,
      );

  @override
  Widget build(BuildContext context) =>
      CameraCaptureSheet(cameraProvider: documentCameraProvider, copy: _copy);
}
