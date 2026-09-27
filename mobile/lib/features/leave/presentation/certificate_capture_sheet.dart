import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../core/data/device_camera.dart';
import '../../../core/presentation/camera_capture_sheet.dart';

/// Filing a medical certificate, start to finish — the shared capture flow
/// wearing this feature's words.
///
/// Two decisions the copy encodes, both worth stating:
///
///  * **the rear camera.** A certificate is a page on a table, not a face, so
///    the flow opens `documentCameraProvider` rather than the front lens a
///    check-in uses. Sharing one implementation is what makes that a
///    parameter instead of a second copy of a hundred-line widget.
///
///  * **the frame is sent as it was taken.** Nothing is re-encoded here: the
///    server validates the file's own bytes (`mimes` + `mimetypes` + a
///    byte-level content check) and a client that re-encoded first would be
///    handing it something other than what a reader will open later. A
///    photograph at the camera's preview resolution is comfortably inside
///    the configured size ceiling.
///
/// The upload itself is the caller's business — this returns bytes, or null
/// if the person backed out.
class CertificateCaptureSheet extends StatelessWidget {
  const CertificateCaptureSheet({super.key});

  static const _copy = CameraCaptureCopy(
    title: 'Medical certificate',
    description:
        'Photograph the certificate you were given. It is stored privately '
        'and read only by the people who review your leave.',
    permissionDenied: 'The camera is needed to photograph your certificate.',
    noCamera:
        'This device has no camera, so the certificate cannot be photographed.',
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
