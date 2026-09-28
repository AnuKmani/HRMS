import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../core/data/device_camera.dart';
import '../../../core/presentation/camera_capture_sheet.dart';

/// One site-report photograph, start to finish.
///
/// The flow itself is [CameraCaptureSheet] — a work front and a medical
/// certificate are the same six states with a different subject. What is
/// specific here is the copy: the camera is refused *after* a report has
/// already been typed, so the sentence has to say what is lost (evidence of
/// the day's work) rather than what is wanted.
class SiteReportPhotoSheet extends StatelessWidget {
  const SiteReportPhotoSheet({super.key});

  static const _copy = CameraCaptureCopy(
    title: 'Site photograph',
    description:
        'The work front as it stands today. These go on the report itself '
        'and are visible only to the people who can read that report.',
    permissionDenied:
        'The camera is needed to photograph the work. You can still file '
        'the report without a photo, but a photograph is what makes it '
        'evidence.',
    noCamera: 'This device has no camera, so no photograph can be taken.',
  );

  /// Opens the sheet and resolves to the raw bytes, or null if cancelled.
  ///
  /// The **rear** camera: a document certificate is the only other caller
  /// of [documentCameraProvider], and a work front is never on the other
  /// lens.
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
